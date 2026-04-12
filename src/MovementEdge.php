<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Contracts\PlannerRuleInterface;
use Nandan108\SlotFlow\Contracts\PolicyInterface;
use Nandan108\SlotFlow\Contracts\ShipmentCalendarRuleInterface;

/**
 * One directed movement edge between two slots.
 *
 * @api
 */
final class MovementEdge
{
    /**
     * Create a directed edge between two slots.
     */
    public function __construct(
        public readonly Slot $from,
        public readonly Slot $to,
        public readonly ?string $label = null,
        public readonly array $attributes = [],
    ) {
    }

    /**
     * Return a readable `(from) -> (to)` representation of the edge.
     */
    public function __toString(): string
    {
        return "($this->from) -> ($this->to)";
    }

    /**
     * Create a copy of the edge with merged metadata.
     */
    public function meta(array $attributes): self
    {
        return new self($this->from, $this->to, $this->label, $attributes + $this->attributes);
    }

    /**
     * Return all declared policies attached to the edge.
     *
     * @return list<PolicyInterface>
     */
    public function policies(): array
    {
        return PolicyBuckets::all($this->attributes);
    }

    /**
     * Return all planner rules declared on the edge.
     *
     * @return list<PlannerRuleInterface>
     */
    public function plannerRules(): array
    {
        return PolicyBuckets::planner($this->attributes);
    }

    /**
     * Return shipment calendar rules declared on the edge.
     *
     * @return list<ShipmentCalendarRuleInterface>
     */
    public function shipmentCalendarRules(): array
    {
        return PolicyBuckets::shipmentCalendar($this->attributes);
    }
}
