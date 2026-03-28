<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Solvers;

use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeFilterPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Contracts\ScheduleSolverInterface;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\Internal\FlowStep;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementSchedule;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Results\ScheduledStep;
use Nandan108\SlotFlow\Results\ScheduleMilestone;
use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\FlowContext;
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
        foreach ($this->candidateSourceSlots($steps[0]['edges'], $state, $quantity) as $source) {
            $plan = $this->earliestPath(
                timedSpace: $timedSpace,
                start: $timedSpace->slot($source['slot'], $originTime),
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
            $lastEdge = $path[count($path) - 1];
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
     * @return list<array{step: FlowStep, edges: list<MovementEdge>}>
     */
    private function resolveStepEdges(SlotSpace $space, Flow $flow, array $params): array
    {
        /** @var list<array{step: FlowStep, edges: list<MovementEdge>}> $resolved */
        $resolved = [];

        foreach ($flow->steps() as $step) {
            $resolved[] = [
                'step'  => $step,
                'edges' => $this->resolveOneStepEdges($space, $step, $params),
            ];
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
            $labels = array_values(array_filter(
                array_map(
                    fn (string $label): string => $this->resolveStringParameter($label, $params),
                    $step->edgeLabels,
                ),
                static fn (string $label): bool => '' !== $label,
            ));

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
     * @param list<array{step: FlowStep, edges: list<MovementEdge>}> $stepEdges
     * @param array<string, string>                                  $params
     *
     * @return ?array{path: list<TimedMovementEdge>, quantity: int|float}
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
        /** @var array<string, array{slot: TimedSlot, path: list<TimedMovementEdge>, quantity: int|float, inventory: QuantityState}> $candidates */
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
            /** @var array<string, array{slot: TimedSlot, path: list<TimedMovementEdge>, quantity: int|float, inventory: QuantityState}> $next */
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
                    if ($movable <= 0) {
                        continue;
                    }

                    $candidatePath = [...$candidate['path'], $timedEdge];
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

            if ([] === $next) {
                return null;
            }

            $candidates = $next;
        }

        /** @var ?array{slot: TimedSlot, path: list<TimedMovementEdge>, quantity: int|float, inventory: QuantityState} $best */
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

    /**
     * @param list<MovementEdge>    $edges
     * @param array<string, string> $params
     *
     * @return array<string, array{edge: MovementEdge, quantity: int|float}>
     */
    private function availableEdgesForStep(
        FlowStep $step,
        array $edges,
        QuantityState $inventory,
        int | float $quantity,
        array $params,
    ): array {
        $context = [] === $params ? [] : ['params' => $params];
        $stepContext = new FlowContext($inventory->space(), $edges, $inventory, $quantity, null, $context);

        foreach ($step->filterPolicies as $policy) {
            if (!is_callable($policy) && !$policy instanceof EdgeFilterPolicyInterface) {
                continue;
            }

            $edges = $this->filterEdges($policy, $stepContext);
            $stepContext = new FlowContext($inventory->space(), $edges, $inventory, $quantity, null, $context);
        }

        foreach ($step->orderingPolicies as $policy) {
            if (!is_callable($policy) && !$policy instanceof EdgeOrderingPolicyInterface) {
                continue;
            }

            $edges = $this->orderEdges($policy, $stepContext);
            $stepContext = new FlowContext($inventory->space(), $edges, $inventory, $quantity, null, $context);
        }

        /** @var array<string, int|float> $decisionQuantities */
        $decisionQuantities = [];
        if ([] !== $step->allocationPolicies) {
            foreach ($step->allocationPolicies as $policy) {
                if (!is_callable($policy) && !$policy instanceof AllocationPolicyInterface) {
                    continue;
                }

                $decisions = $this->allocateEdges($policy, $stepContext);
                if ([] === $decisions) {
                    continue;
                }

                $edges = [];
                $decisionQuantities = [];
                foreach ($decisions as $decision) {
                    $edgeId = $this->edgeKey($decision->edge);
                    $edges[$edgeId] = $decision->edge;
                    /** @psalm-suppress InvalidOperand */
                    $decisionQuantities[$edgeId] = ($decisionQuantities[$edgeId] ?? 0) + $decision->quantity;
                }

                $edges = array_values($edges);
                $stepContext = new FlowContext($inventory->space(), $edges, $inventory, $quantity, null, $context);
            }
        }

        /** @var array<string, array{edge: MovementEdge, quantity: int|float}> $available */
        $available = [];
        foreach ($edges as $edge) {
            $edgeId = $this->edgeKey($edge);
            $requested = isset($decisionQuantities[$edgeId])
                ? min($quantity, $decisionQuantities[$edgeId])
                : $quantity;
            $movable = $this->limitMovable($inventory, $edge, $requested, $quantity, $step, $context);
            if ($movable <= 0) {
                continue;
            }

            $available[$edgeId] = ['edge' => $edge, 'quantity' => $movable];
        }

        return $available;
    }

    /**
     * @param (callable(FlowContext): list<MovementEdge>)|EdgeFilterPolicyInterface $policy
     *
     * @return list<MovementEdge>
     */
    private function filterEdges(callable | EdgeFilterPolicyInterface $policy, FlowContext $context): array
    {
        if ($policy instanceof EdgeFilterPolicyInterface) {
            return $policy->filterEdges($context);
        }

        return $policy($context);
    }

    /**
     * @param (callable(FlowContext): list<MovementEdge>)|EdgeOrderingPolicyInterface $policy
     *
     * @return list<MovementEdge>
     */
    private function orderEdges(callable | EdgeOrderingPolicyInterface $policy, FlowContext $context): array
    {
        if ($policy instanceof EdgeOrderingPolicyInterface) {
            return $policy->orderEdges($context);
        }

        return $policy($context);
    }

    /**
     * @param (callable(FlowContext): list<AllocationDecision>)|AllocationPolicyInterface $policy
     *
     * @return list<AllocationDecision>
     */
    private function allocateEdges(callable | AllocationPolicyInterface $policy, FlowContext $context): array
    {
        if ($policy instanceof AllocationPolicyInterface) {
            return $policy->allocate($context);
        }

        return $policy($context);
    }

    /**
     * @param array<mixed> $context
     */
    private function limitMovable(
        QuantityState $inventory,
        MovementEdge $edge,
        int | float $requested,
        int | float $quantity,
        FlowStep $step,
        array $context,
    ): int | float {
        $available = $edge->from->isNil()
            ? $requested
            : min($requested, $inventory->get($edge->from));

        $limit = $available;
        $stepContext = new FlowContext($inventory->space(), [$edge], $inventory, $quantity, null, $context);

        foreach ($step->quantityConstraintPolicies as $policy) {
            if (!is_callable($policy) && !$policy instanceof QttyConstraintPolicyInterface) {
                continue;
            }

            $policyLimit = $policy instanceof QttyConstraintPolicyInterface
                ? $policy->constraint($edge, $stepContext)
                : $policy($edge, $stepContext);

            if (!is_int($policyLimit) && !is_float($policyLimit)) {
                continue;
            }

            $limit = min($limit, $policyLimit);
        }

        return max(0, $limit);
    }

    private function applyMovement(QuantityState $inventory, MovementEdge $edge, int | float $quantity): QuantityState
    {
        $updated = $inventory->copy();

        if (!$edge->from->isNil()) {
            $updated->add($edge->from, -$quantity);
        }

        if (!$edge->to->isNil()) {
            $updated->add($edge->to, $quantity);
        }

        return $updated;
    }

    private function edgeKey(MovementEdge $edge): string
    {
        return $edge->from->key.'>'.$edge->to->key.'|'.($edge->label ?? '').'|'.serialize($edge->attributes);
    }
}
