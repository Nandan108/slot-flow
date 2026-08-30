<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Solvers;

use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeFilterPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\Internal\FlowStep;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\FlowContext;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Solvers\Concerns\ResolvesFlowParameters;

/**
 * Shared helper methods for path-based planning and scheduling solvers.
 *
 * @internal
 */
abstract class AbstractPathSolver
{
    use ResolvesFlowParameters;

    /**
     * Resolve each flow step into its admissible base movement edges.
     *
     * @param array<string, string> $params
     *
     * @return list<array{step: FlowStep, edges: list<MovementEdge>}>
     */
    protected function resolveStepEdges(SlotSpace $space, Flow $flow, array $params): array
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
    protected function resolveOneStepEdges(SlotSpace $space, FlowStep $step, array $params): array
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
     * @param list<MovementEdge>    $edges
     * @param array<string, string> $params
     *
     * @return array<string, array{edge: MovementEdge, quantity: int|float}>
     */
    protected function availableEdgesForStep(
        FlowStep $step,
        array $edges,
        QuantityState $inventory,
        int | float $quantity,
        array $params,
        mixed $subject = null,
    ): array {
        $context = [] === $params ? [] : ['params' => $params];
        $stepContext = new FlowContext($inventory->space(), $edges, $inventory, $quantity, $subject, $context);

        foreach ($step->filterPolicies as $policy) {
            if (!is_callable($policy) && !$policy instanceof EdgeFilterPolicyInterface) {
                continue;
            }

            $edges = $this->filterEdges($policy, $stepContext);
            $stepContext = new FlowContext($inventory->space(), $edges, $inventory, $quantity, $subject, $context);
        }

        foreach ($step->orderingPolicies as $policy) {
            if (!is_callable($policy) && !$policy instanceof EdgeOrderingPolicyInterface) {
                continue;
            }

            $edges = $this->orderEdges($policy, $stepContext);
            $stepContext = new FlowContext($inventory->space(), $edges, $inventory, $quantity, $subject, $context);
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
                $stepContext = new FlowContext($inventory->space(), $edges, $inventory, $quantity, $subject, $context);
            }
        }

        /** @var array<string, array{edge: MovementEdge, quantity: int|float}> $available */
        $available = [];
        foreach ($edges as $edge) {
            $edgeId = $this->edgeKey($edge);
            $requested = isset($decisionQuantities[$edgeId])
                ? min($quantity, $decisionQuantities[$edgeId])
                : $quantity;
            $movable = $this->limitMovable($inventory, $edge, $requested, $quantity, $step, $context, $subject);
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
    protected function filterEdges(callable | EdgeFilterPolicyInterface $policy, FlowContext $context): array
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
    protected function orderEdges(callable | EdgeOrderingPolicyInterface $policy, FlowContext $context): array
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
    protected function allocateEdges(callable | AllocationPolicyInterface $policy, FlowContext $context): array
    {
        if ($policy instanceof AllocationPolicyInterface) {
            return $policy->allocate($context);
        }

        return $policy($context);
    }

    /**
     * @param array<mixed> $context
     */
    protected function limitMovable(
        QuantityState $inventory,
        MovementEdge $edge,
        int | float $requested,
        int | float $quantity,
        FlowStep $step,
        array $context,
        mixed $subject = null,
    ): int | float {
        $available = $this->availableOn($inventory, $edge, $requested);

        $limit = $available;
        $stepContext = new FlowContext($inventory->space(), [$edge], $inventory, $quantity, $subject, $context);

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

    /**
     * How much this edge could supply before any quantity constraint is consulted.
     *
     * Split out from {@see limitMovable()} so a trace can report availability and the post-constraint
     * limit separately: together they say whether an edge moved nothing because the source was empty
     * or because a policy capped it, which are different problems.
     */
    protected function availableOn(QuantityState $inventory, MovementEdge $edge, int | float $requested): int | float
    {
        return $edge->from->isNil()
            ? $requested
            : min($requested, $inventory->get($edge->from));
    }

    protected function applyMovement(QuantityState $inventory, MovementEdge $edge, int | float $quantity): QuantityState
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

    protected function edgeKey(MovementEdge $edge): string
    {
        return $edge->from->key.'>'.$edge->to->key.'|'.($edge->label ?? '').'|'.serialize($edge->attributes);
    }
}
