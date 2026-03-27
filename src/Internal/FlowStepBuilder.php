<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Internal;

use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeFilterPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\FlowContext;
use Nandan108\SlotFlow\SlotSpace;

/**
 * Fluent builder for configuring the most recently added flow step.
 *
 * @psalm-import-type TSlotPattern from SlotSpace
 *
 * @api
 */
final class FlowStepBuilder
{
    private Flow $flow;
    private FlowStep $step;

    public function __construct(Flow $flow, FlowStep $step)
    {
        $this->flow = $flow;
        $this->step = $step;
    }

    /**
     * Add one or more ordering policies for the current step.
     *
     * In the final result, earlier arguments have higher precedence than later ones.
     * For example, `orderBy($primary, $secondary)` makes `$primary` the primary
     * ordering and `$secondary` a tie-breaker within equal `$primary` groups.
     *
     * Later `orderBy()` calls add lower-level tie-breakers.
     * This relies on stable sorting: when a later policy considers two edges equal,
     * their previous order is preserved, so earlier policies keep higher precedence.
     *
     * @psalm-param EdgeOrderingPolicyInterface|callable(FlowContext): list<MovementEdge> ...$policies
     *
     * @api
     */
    public function orderBy(EdgeOrderingPolicyInterface | callable ...$policies): self
    {
        foreach ($policies as $policy) {
            array_unshift($this->step->orderingPolicies, $policy);
        }

        return $this;
    }

    /**
     * Add a filtering policy for the current step.
     *
     * @psalm-param EdgeFilterPolicyInterface|callable(FlowContext): list<MovementEdge> $policy
     *
     * @api
     */
    public function filter(EdgeFilterPolicyInterface | callable $policy): self
    {
        $this->step->filterPolicies[] = $policy;

        return $this;
    }

    /**
     * Add a per-edge quantity constraint policy for the current step.
     *
     * @psalm-param QttyConstraintPolicyInterface|callable(MovementEdge, FlowContext): mixed $policy
     *
     * @api
     */
    public function constraint(QttyConstraintPolicyInterface | callable $policy): self
    {
        $this->step->quantityConstraintPolicies[] = $policy;

        return $this;
    }

    /**
     * Add an allocation policy for the current step.
     *
     * @psalm-param AllocationPolicyInterface|callable(FlowContext): list<AllocationDecision> $policy
     *
     * @api
     */
    public function allocate(AllocationPolicyInterface | callable $policy): self
    {
        $this->step->allocationPolicies[] = $policy;

        return $this;
    }

    /**
     * Start the next step in the flow.
     *
     * @param array<string|int, string|null>|string|null $from
     * @param array<string|int, string|null>|string|null $to
     *
     * @psalm-param TSlotPattern $from
     * @psalm-param TSlotPattern $to
     *
     * @api
     */
    public function move(string | array | null $from, string | array | null $to): FlowStepBuilder
    {
        return $this->flow->move($from, $to);
    }

    /**
     * Add a step that resolves its candidate edges from one or more labeled edge rules.
     *
     * @param non-empty-string ...$edgeLabels
     */
    public function stepByLabeledEdges(string ...$edgeLabels): FlowStepBuilder
    {
        return $this->flow->stepByLabeledEdges(...$edgeLabels);
    }

    /**
     * Start the next step in the flow.
     *
     * @param array<string|int, string|null>|string|null $from
     *
     * @psalm-param TSlotPattern $from
     *
     * @api
     */
    public function destroy(string | array | null $from): FlowStepBuilder
    {
        return $this->flow->destroy($from);
    }

    /**
     * Start the next step in the flow.
     *
     * @param array<string|int, string|null>|string|null $to
     *
     * @psalm-param TSlotPattern $to
     *
     * @api
     */
    public function create(string | array | null $to): FlowStepBuilder
    {
        return $this->flow->create($to);
    }
}
