<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Contracts\DemandReleasePolicyInterface;
use Nandan108\SlotFlow\Contracts\ShipmentCalendarInterface;
use Nandan108\SlotFlow\Contracts\ShipmentPlannerInterface;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Policies\PartialShipmentPolicy;

/**
 * Immutable request for multi-line demand scheduling.
 *
 * @api
 */
final class DemandScheduleRequest
{
    /** Default flow applied to lines that do not override it. */
    public readonly Flow | string $flow;
    /** Default target slot applied to lines that do not override it. */
    public readonly Slot | string $target;
    /** @var array<string, QuantityState> Current per-subject inventory keyed by subject key. */
    public readonly array $statesBySubjectKey;
    /** @var array<string, string> Default flow params shared by every line. */
    public readonly array $params;
    /** Order-level release policy used to turn line schedules into shipments. */
    public readonly DemandReleasePolicyInterface $releasePolicy;
    /** Start time for the whole demand scheduling run, normalized to the space time axis. */
    public readonly int $startTime;
    /** Shipment planner used to convert arrivals into planned shipments. */
    public readonly ShipmentPlannerInterface $shipmentPlanner;
    /** Optional order-level calendar used to adjust shipment release times. */
    public readonly ?ShipmentCalendarInterface $shipmentCalendar;
    /** Time window to hold arrivals for possible shipment consolidation before policy evaluation. */
    public readonly int $consolidationWindow;

    /**
     * Create one order-level demand scheduling request.
     *
     * @param array<string, QuantityState> $statesBySubjectKey
     * @param array<string, scalar|null>   $params
     * @param Flow|non-empty-string        $flow
     * @param Slot|non-empty-string        $target
     */
    public function __construct(
        /** Multi-line demand to schedule. */
        public readonly Demand $demand,
        /** Slot space shared by all demand lines. */
        public readonly SlotSpace $space,
        Flow | string $flow,
        Slot | string $target,
        array $statesBySubjectKey = [],
        \DateTimeImmutable | int | string $startTime = 0,
        array $params = [],
        ?DemandReleasePolicyInterface $releasePolicy = null,
        ?ShipmentPlannerInterface $shipmentPlanner = null,
        ?ShipmentCalendarInterface $shipmentCalendar = null,
        int $consolidationWindow = 0,
    ) {
        $this->flow = $flow;
        $this->target = $target;
        $this->statesBySubjectKey = $statesBySubjectKey;
        if (null !== $space->timeAxis) {
            $this->startTime = $space->timeAxis->parse($startTime);
        } elseif (is_int($startTime)) {
            $this->startTime = $startTime;
        } elseif (is_string($startTime) && ctype_digit($startTime)) {
            $this->startTime = (int) $startTime;
        } else {
            throw new SlotFlowInvalidArgumentException(
                'Demand schedule requests without a TimeAxis require an integer bucket start time.',
                ['start_time' => $startTime],
            );
        }
        $this->params = array_map('strval', $params);
        $this->releasePolicy = $releasePolicy ?? new PartialShipmentPolicy();
        $this->shipmentPlanner = $shipmentPlanner ?? new TimelineShipmentPlanner();
        $this->shipmentCalendar = $shipmentCalendar;
        $this->consolidationWindow = max(0, $consolidationWindow);
    }
}
