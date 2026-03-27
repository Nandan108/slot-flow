<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Solvers;

use Nandan108\SlotFlow\Contracts\ScheduleSolverInterface;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\Internal\FlowStep;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementSchedule;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Results\ScheduledStep;
use Nandan108\SlotFlow\Results\ScheduleMilestone;
use Nandan108\SlotFlow\ScheduleRequest;
use Nandan108\SlotFlow\Slot;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Time\TimedMovementEdge;
use Nandan108\SlotFlow\Time\TimedSlot;
use Nandan108\SlotFlow\Time\TimedSlotSpace;

/**
 * Plans the earliest-arriving schedule that satisfies a requested quantity.
 *
 * @api
 */
final class EarliestArrivalSolver implements ScheduleSolverInterface
{
    /**
     * Build one earliest-arrival movement schedule from a schedule request.
     */
    #[\Override]
    public function schedule(ScheduleRequest $request): MovementSchedule
    {
        $state = $request->state;
        $space = $request->space;
        $flow = $request->flow;
        $quantity = $request->quantity;
        $resolvedTarget = $request->target;
        $originTime = $request->originTime;
        $params = $request->params;

        // Expand the timeless space into a timed search space and resolve the already-normalized flow steps.
        $timedSpace = TimedSlotSpace::fromBaseSpace($space);
        $steps = $this->resolveStepEdges($space, $flow, $params);
        if ([] === $steps) {
            return new MovementSchedule([], $quantity, []);
        }

        /** @var list<array{source: Slot, quantity: int|float, path: list<TimedMovementEdge>, arrival: int}> $candidates */
        $candidates = [];
        // For each currently stocked source in the first step, search the earliest full timed path to the target.
        foreach ($this->candidateSourceSlots($steps[0], $state) as $source) {
            $path = $this->earliestPath(
                timedSpace: $timedSpace,
                start: $timedSpace->slot($source, $originTime),
                stepEdges: $steps,
                target: $resolvedTarget,
            );

            if (null === $path || [] === $path) {
                continue;
            }

            $lastEdge = $path[count($path) - 1];
            $candidates[] = [
                'source'   => $source,
                'quantity' => $state->get($source),
                'path'     => $path,
                'arrival'  => $lastEdge->to->timeIndex,
            ];
        }

        // Rank feasible source-path candidates by earliest arrival so allocation prefers the fastest route.
        usort(
            $candidates,
            static fn (array $left, array $right): int => $left['arrival'] <=> $right['arrival'],
        );

        $remaining = $quantity;
        $stepId = 0;
        /** @var list<ScheduledStep> $scheduledSteps */
        $scheduledSteps = [];
        /** @var list<ScheduleMilestone> $milestones */
        $milestones = [];

        // Emit scheduled steps and milestones until the requested quantity is fully covered or candidates run out.
        foreach ($candidates as $candidate) {
            if ($remaining <= 0) {
                break;
            }

            $allocated = min($candidate['quantity'], $remaining);
            foreach ($candidate['path'] as $edge) {
                ++$stepId;
                $scheduledStep = new ScheduledStep('sched-'.$stepId, $edge, $allocated);
                $scheduledSteps[] = $scheduledStep;
                $milestones[] = $scheduledStep->milestone();
            }

            /** @psalm-suppress InvalidOperand */
            $remaining -= $allocated;
        }

        return new MovementSchedule($scheduledSteps, $remaining, $milestones);
    }

    /**
     * Resolve each flow step into its admissible base movement edges.
     *
     * @param array<string, string> $params
     *
     * @return list<list<MovementEdge>>
     */
    private function resolveStepEdges(SlotSpace $space, Flow $flow, array $params): array
    {
        /** @var list<list<MovementEdge>> $resolved */
        $resolved = [];

        foreach ($flow->steps() as $step) {
            $resolved[] = $this->resolveOneStepEdges($space, $step, $params);
        }

        return $resolved;
    }

    /**
     * @param array<string, string> $params
     *
     * @return list<MovementEdge>
     */
    private function resolveOneStepEdges(SlotSpace $space, FlowStep $step, array $params): array
    {
        if (null !== $step->edgeLabels) {
            /** @var list<non-empty-string> $labels */
            $labels = array_map(
                fn (string $label): string => $this->resolveStringParameter($label, $params),
                $step->edgeLabels,
            );

            return $space->edgesByLabels($labels);
        }

        /** @psalm-suppress ArgumentTypeCoercion, MixedArgumentTypeCoercion */
        return array_values($space->edgesBetween(
            $this->resolvePatternParameters($step->from, $params),
            $this->resolvePatternParameters($step->to, $params),
        ));
    }

    /**
     * Return source slots from the first step that currently have positive available quantity.
     *
     * @param list<MovementEdge> $edges
     *
     * @return list<Slot>
     */
    private function candidateSourceSlots(array $edges, QuantityState $state): array
    {
        /** @var array<string, Slot> $sources */
        $sources = [];
        foreach ($edges as $edge) {
            if ($edge->from->isNil()) {
                continue;
            }

            if ($state->get($edge->from) <= 0) {
                continue;
            }

            $sources[$edge->from->key] = $edge->from;
        }

        return array_values($sources);
    }

    /**
     * Find the earliest complete timed path from one source through the ordered flow steps.
     *
     * @param list<list<MovementEdge>> $stepEdges
     *
     * @return ?list<TimedMovementEdge>
     */
    private function earliestPath(
        TimedSlotSpace $timedSpace,
        TimedSlot $start,
        array $stepEdges,
        Slot $target,
    ): ?array {
        /** @var array<string, array{slot: TimedSlot, path: list<TimedMovementEdge>}> $candidates */
        $candidates = [$start->key => ['slot' => $start, 'path' => []]];

        foreach ($stepEdges as $index => $edgesForStep) {
            $allowed = [];
            foreach ($edgesForStep as $edge) {
                $allowed[$edge->from->key.'>'.$edge->to->key] = true;
            }

            $isFinalStep = $index === array_key_last($stepEdges);
            /** @var array<string, array{slot: TimedSlot, path: list<TimedMovementEdge>}> $next */
            $next = [];

            foreach ($candidates as $candidate) {
                foreach ($timedSpace->getEdgesFrom($candidate['slot']) as $timedEdge) {
                    if (null === $timedEdge->baseEdge) {
                        continue;
                    }

                    $key = $timedEdge->baseEdge->from->key.'>'.$timedEdge->baseEdge->to->key;
                    if (!isset($allowed[$key])) {
                        continue;
                    }

                    if ($isFinalStep && $timedEdge->to->slot->key !== $target->key) {
                        continue;
                    }

                    $candidatePath = [...$candidate['path'], $timedEdge];
                    $toKey = $timedEdge->to->key;
                    if (
                        !isset($next[$toKey])
                        || $timedEdge->to->timeIndex < $next[$toKey]['slot']->timeIndex
                    ) {
                        $next[$toKey] = ['slot' => $timedEdge->to, 'path' => $candidatePath];
                    }
                }
            }

            if ([] === $next) {
                return null;
            }

            $candidates = $next;
        }

        /** @var ?array{slot: TimedSlot, path: list<TimedMovementEdge>} $best */
        $best = null;
        foreach ($candidates as $candidate) {
            if (null === $best || $candidate['slot']->timeIndex < $best['slot']->timeIndex) {
                $best = $candidate;
            }
        }

        return $best['path'] ?? null;
    }

    /**
     * Resolve `{param}` placeholders inside slot-pattern inputs.
     *
     * @param array<string, string>                      $params
     * @param string|array<int|string, string|null>|null $pattern
     */
    private function resolvePatternParameters(string | array | null $pattern, array $params): string | array | null
    {
        if (null === $pattern) {
            return null;
        }

        if (is_string($pattern)) {
            return $this->resolveStringParameter($pattern, $params);
        }

        $resolved = [];
        foreach ($pattern as $key => $value) {
            $resolved[$key] = is_string($value)
                ? $this->resolveStringParameter($value, $params)
                : $value;
        }

        return $resolved;
    }

    /**
     * Resolve a single parameterized string using the provided scalar params.
     *
     * @param array<string, string> $params
     */
    private function resolveStringParameter(string $value, array $params): string
    {
        if (!$params) {
            return $value;
        }

        if (1 === preg_match('/^\{([-a-z_]*)\}$/i', $value, $matches)) {
            return $params[$matches[1]] ?? $value ?: $value;
        }

        $resolved = preg_replace_callback(
            '/\{([-a-z_]*)\}/i',
            static function (array $matches) use ($params) {
                $resolved = $params[$matches[1]] ?? null;

                return $resolved ?? "\{$matches[0]\}" ?: "\{$matches[0]\}";
            },
            $value,
        );

        return $resolved ?? $value ?: $value;
    }
}
