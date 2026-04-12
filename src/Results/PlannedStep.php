<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Results;

use Nandan108\SlotFlow\Contracts\PlannerRuleInterface;
use Nandan108\SlotFlow\Contracts\PolicyInterface;
use Nandan108\SlotFlow\Contracts\ShipmentCalendarRuleInterface;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\PolicyBuckets;

/**
 * One planned timeless movement.
 *
 * @api
 */
final class PlannedStep
{
    /**
     * Create one timeless planned movement step.
     *
     * @param list<PolicyInterface> $policies
     */
    public function __construct(
        public readonly string $id,
        public readonly MovementEdge $edge,
        public readonly int | float $quantity,
        public readonly array $policies = [],
    ) {
    }

    /**
     * Convert this planned step into quantity-state deltas.
     *
     * @return list<QuantityStateDelta>
     */
    public function deltas(): array
    {
        /** @var list<QuantityStateDelta> $deltas */
        $deltas = [];

        if (!$this->edge->from->isNil()) {
            $deltas[] = new QuantityStateDelta($this->edge->from, -$this->quantity);
        }

        if (!$this->edge->to->isNil()) {
            $deltas[] = new QuantityStateDelta($this->edge->to, $this->quantity);
        }

        return $deltas;
    }

    /**
     * Return a copy of this step with a different planned quantity.
     */
    public function withQuantity(int | float $quantity): self
    {
        return new self($this->id, $this->edge, $quantity, $this->policies);
    }

    /**
     * Return all applicable policies for the planned step, merging step first and edge second.
     *
     * @return list<PolicyInterface>
     */
    public function policies(): array
    {
        $policies = [...$this->policies, ...$this->edge->policies()];

        return PolicyBuckets::resolveCategory(
            $policies,
            PolicyBuckets::matchesAny(...),
        );
    }

    /**
     * Return the planner rules applicable to the planned step.
     *
     * @return list<PlannerRuleInterface>
     */
    public function plannerRules(): array
    {
        return PolicyBuckets::resolveCategory(
            $this->policies(),
            static fn (PolicyInterface $policy): bool => $policy instanceof PlannerRuleInterface,
        );
    }

    /**
     * Return shipment calendar rules applicable to the planned step.
     *
     * @return list<ShipmentCalendarRuleInterface>
     */
    public function shipmentCalendarRules(): array
    {
        return PolicyBuckets::resolveCategory(
            $this->policies(),
            static fn (PolicyInterface $policy): bool => $policy instanceof ShipmentCalendarRuleInterface,
        );
    }
}
