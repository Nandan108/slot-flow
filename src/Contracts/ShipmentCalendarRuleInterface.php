<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\DemandReleaseContext;
use Nandan108\SlotFlow\Results\DemandShipmentLine;
use Nandan108\SlotFlow\Results\ScheduledStep;

/**
 * Planner rule that delays shipment release or handover times for a line.
 *
 * @api
 */
interface ShipmentCalendarRuleInterface extends PlannerRuleInterface
{
    /**
     * Return the earliest allowed release time after applying this rule.
     */
    public function releaseTime(
        DemandReleaseContext $context,
        DemandShipmentLine $line,
        ScheduledStep $step,
        int $earliestReleaseTime,
    ): int;
}
