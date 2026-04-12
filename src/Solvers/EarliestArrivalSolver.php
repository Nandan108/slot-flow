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
use Nandan108\SlotFlow\Time\TimedMovementEdge;
use Nandan108\SlotFlow\Time\TimedSlot;
use Nandan108\SlotFlow\Time\TimedSlotSpace;

/**
 * Plans the earliest-arriving schedule that satisfies a requested quantity.
 *
 * @api
 */
final class EarliestArrivalSolver extends AbstractPathSolver implements ScheduleSolverInterface
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
        $startTime = $request->startTime;
        $params = $request->params;

        // Expand the timeless space into a timed search space and resolve the already-normalized flow steps.
        $timedSpace = TimedSlotSpace::fromBaseSpace($space);
        $steps = $this->resolveStepEdges($space, $flow, $params);
        if ([] === $steps) {
            return new MovementSchedule([], $quantity, []);
        }

        /** @var list<array{source: Slot, quantity: int|float, path: list<array{step: FlowStep, edge: TimedMovementEdge}>, arrival: int}> $candidates */
        $candidates = [];
        // For each currently stocked source in the first step, search the earliest full timed path to the target.
        foreach ($this->candidateSourceSlots($steps[0]['edges'], $state, $quantity) as $source) {
            $plan = $this->earliestPath(
                timedSpace: $timedSpace,
                start: $timedSpace->slot($source['slot'], $startTime),
                startingQuantity: $source['quantity'],
                startingState: $state->copy(),
                stepEdges: $steps,
                target: $resolvedTarget,
                params: $params,
            );

            if (null === $plan || [] === $plan['path']) {
                continue;
            }

            $path = $plan['path'];
            $lastEdge = $path[count($path) - 1]['edge'];
            $candidates[] = [
                'source'   => $source['slot'],
                'quantity' => $plan['quantity'],
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
            foreach ($candidate['path'] as $pathStep) {
                ++$stepId;
                $scheduledStep = new ScheduledStep('sched-'.$stepId, $pathStep['edge'], $allocated, $pathStep['step']->policies);
                $scheduledSteps[] = $scheduledStep;
                $milestones[] = $scheduledStep->milestone();
            }

            /** @psalm-suppress InvalidOperand */
            $remaining -= $allocated;
        }

        return new MovementSchedule($scheduledSteps, $remaining, $milestones);
    }

    /**
     * Return source slots from the first step that currently have positive available quantity.
     *
     * @param list<MovementEdge> $edges
     *
     * @return list<array{slot: Slot, quantity: int|float}>
     */
    private function candidateSourceSlots(array $edges, QuantityState $state, int | float $requestedQuantity): array
    {
        /** @var array<string, array{slot: Slot, quantity: int|float}> $sources */
        $sources = [];

        foreach ($edges as $edge) {
            $available = $edge->from->isNil()
                ? $requestedQuantity
                : $state->get($edge->from);

            if ($available <= 0) {
                continue;
            }

            $sources[$edge->from->key] = [
                'slot'     => $edge->from,
                'quantity' => $available,
            ];
        }

        return array_values($sources);
    }

    /**
     * Find the earliest complete timed path from one source through the ordered flow steps.
     *
     * @psalm-type TCandidate = array{
     *     slot: TimedSlot,
     *     path: list<array{step: FlowStep, edge: TimedMovementEdge}>,
     *     quantity: int|float,
     *     inventory: QuantityState
     * }
     *
     * @param list<array{step: FlowStep, edges: list<MovementEdge>}> $stepEdges
     * @param array<string, string>                                  $params
     *
     * @return ?array{path: list<array{step: FlowStep, edge: TimedMovementEdge}>, quantity: int|float}
     */
    private function earliestPath(
        TimedSlotSpace $timedSpace,
        TimedSlot $start,
        int | float $startingQuantity,
        QuantityState $startingState,
        array $stepEdges,
        Slot $target,
        array $params,
    ): ?array {
        /** @psalm-var array<string, TCandidate> $candidates */
        $candidates = [
            $start->key => [
                'slot'      => $start,
                'path'      => [],
                'quantity'  => $startingQuantity,
                'inventory' => $startingState,
            ],
        ];

        foreach ($stepEdges as $index => $stepData) {
            $flowStep = $stepData['step'];
            $edgesForStep = $stepData['edges'];

            $isFinalStep = $index === array_key_last($stepEdges);
            /** @var array<string, array{slot: TimedSlot, path: list<array{step: FlowStep, edge: TimedMovementEdge}>, quantity: int|float, inventory: QuantityState}> $next */
            $next = [];

            foreach ($candidates as $candidate) {
                $allowed = $this->availableEdgesForStep(
                    step: $flowStep,
                    edges: $edgesForStep,
                    inventory: $candidate['inventory'],
                    quantity: $candidate['quantity'],
                    params: $params,
                );

                if ([] === $allowed) {
                    continue;
                }

                foreach ($timedSpace->getEdgesFrom($candidate['slot']) as $timedEdge) {
                    if (null === $timedEdge->baseEdge) {
                        continue;
                    }

                    $edgeId = $this->edgeKey($timedEdge->baseEdge);
                    if (!isset($allowed[$edgeId])) {
                        continue;
                    }

                    if ($isFinalStep && $timedEdge->to->slot->key !== $target->key) {
                        continue;
                    }

                    $movable = min($candidate['quantity'], $allowed[$edgeId]['quantity']);

                    if ($movable) {
                        $candidatePath = [...$candidate['path'], ['step' => $flowStep, 'edge' => $timedEdge]];
                        $toKey = $timedEdge->to->key;
                        if (
                            !isset($next[$toKey])
                            || $timedEdge->to->timeIndex < $next[$toKey]['slot']->timeIndex
                            || (
                                $timedEdge->to->timeIndex === $next[$toKey]['slot']->timeIndex
                                && $movable > $next[$toKey]['quantity']
                            )
                        ) {
                            $next[$toKey] = [
                                'slot'      => $timedEdge->to,
                                'path'      => $candidatePath,
                                'quantity'  => $movable,
                                'inventory' => $this->applyMovement($candidate['inventory'], $timedEdge->baseEdge, $movable),
                            ];
                        }
                    }

                }
            }

            if ([] === $next) {
                return null;
            }

            $candidates = $next;
        }

        /** @psalm-var ?TCandidate $best */
        $best = null;
        foreach ($candidates as $candidate) {
            if (
                null === $best
                || $candidate['slot']->timeIndex < $best['slot']->timeIndex
                || (
                    $candidate['slot']->timeIndex === $best['slot']->timeIndex
                    && $candidate['quantity'] > $best['quantity']
                )
            ) {
                $best = $candidate;
            }
        }

        return null === $best
            ? null
            : ['path' => $best['path'], 'quantity' => $best['quantity']];
    }
}
