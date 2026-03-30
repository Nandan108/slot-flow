<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Time;

use Nandan108\SlotFlow\Contracts\PlannerRuleInterface;
use Nandan108\SlotFlow\Contracts\PolicyInterface;
use Nandan108\SlotFlow\Contracts\ShipmentCalendarRuleInterface;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\PolicyBuckets;

/**
 * One directed movement edge between two timed slots.
 *
 * @api
 */
final class TimedMovementEdge
{
    /**
     * Create one directed edge between two timed slots, optionally linked to its base edge.
     */
    public function __construct(
        public readonly TimedSlot $from,
        public readonly TimedSlot $to,
        public readonly ?MovementEdge $baseEdge = null,
        public readonly ?string $label = null,
        public readonly array $attributes = [],
    ) {
    }

    /**
     * Return a readable edge representation using timed slot keys.
     */
    public function __toString(): string
    {
        return "($this->from) -> ($this->to)";
    }

    /**
     * Return all declared policies attached to the underlying edge.
     *
     * @return list<PolicyInterface>
     */
    public function policies(): array
    {
        return PolicyBuckets::all($this->attributes);
    }

    /**
     * Return all planner rules declared on the underlying edge.
     *
     * @return list<PlannerRuleInterface>
     */
    public function plannerRules(): array
    {
        return PolicyBuckets::planner($this->attributes);
    }

    /**
     * Return shipment calendar rules declared on the underlying edge.
     *
     * @return list<ShipmentCalendarRuleInterface>
     */
    public function shipmentCalendarRules(): array
    {
        return PolicyBuckets::shipmentCalendar($this->attributes);
    }
}
