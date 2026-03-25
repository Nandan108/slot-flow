<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Internal;

use Nandan108\SlotFlow\Cascade;
use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeFilterPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\CascadeContext;
use Nandan108\SlotFlow\SlotSpace;

/**
 * @psalm-import-type TSlotPattern from SlotSpace
 *
 * @api
 */
final class CascadeStepBuilder
{
    private Cascade $cascade;
    private CascadeStep $step;

    public function __construct(Cascade $cascade, CascadeStep $step)
    {
        $this->cascade = $cascade;
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
     * @psalm-param EdgeOrderingPolicyInterface|callable(CascadeContext): list<MovementEdge> ...$policies
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
     * @psalm-param EdgeFilterPolicyInterface|callable(CascadeContext): list<MovementEdge> $policy
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
     * @psalm-param QttyConstraintPolicyInterface|callable(MovementEdge, CascadeContext): mixed $policy
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
     * @psalm-param AllocationPolicyInterface|callable(CascadeContext): list<AllocationDecision> $policy
     *
     * @api
     */
    public function allocate(AllocationPolicyInterface | callable $policy): self
    {
        $this->step->allocationPolicies[] = $policy;

        return $this;
    }

    /**
     * Start the next step in the cascade.
     *
     * @param array<string|int, string|null>|string|null $from
     * @param array<string|int, string|null>|string|null $to
     *
     * @psalm-param TSlotPattern $from
     * @psalm-param TSlotPattern $to
     *
     * @api
     */
    public function move(string | array | null $from, string | array | null $to): CascadeStepBuilder
    {
        return $this->cascade->move($from, $to);
    }

    /**
     * Start the next step in the cascade.
     *
     * @param array<string|int, string|null>|string|null $from
     *
     * @psalm-param TSlotPattern $from
     *
     * @api
     */
    public function destroy(string | array | null $from): CascadeStepBuilder
    {
        return $this->cascade->destroy($from);
    }

    /**
     * Start the next step in the cascade.
     *
     * @param array<string|int, string|null>|string|null $to
     *
     * @psalm-param TSlotPattern $to
     *
     * @api
     */
    public function create(string | array | null $to): CascadeStepBuilder
    {
        return $this->cascade->create($to);
    }
}
