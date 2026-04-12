<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\PlannerRules;

use Nandan108\SlotFlow\Contracts\ShipmentCalendarRuleInterface;
use Nandan108\SlotFlow\DemandReleaseContext;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Results\DemandShipmentLine;
use Nandan108\SlotFlow\Results\ScheduledStep;
use Nandan108\SlotFlow\Time\WeeklyCalendar;

/**
 * Delay shipment release until the next matching weekly pickup or handover moment.
 *
 * @api
 */
final class WeeklyShipmentCalendarRule implements ShipmentCalendarRuleInterface
{
    /**
     * Create one weekly shipment calendar rule around a reusable weekly schedule.
     */
    public function __construct(
        public readonly WeeklyCalendar $calendar,
    ) {
    }

    /**
     * Build one weekly calendar from a weekday => list of moment/window expressions map.
     *
     * Example: `['mon-thu,fri' => ['10:00', '13:00-16:00'], '6,7' => ['09:00']]`
     *
     * @param array<int|string, string|list<string>> $map
     */
    public static function fromMap(array $map): self
    {
        return new self(WeeklyCalendar::fromMap($map));
    }

    /**
     * Return the first shipment release time that matches the configured weekly schedule.
     *
     * @throws SlotFlowInvalidArgumentException
     */
    #[\Override]
    public function releaseTime(
        DemandReleaseContext $context,
        DemandShipmentLine $line,
        ScheduledStep $step,
        int $earliestReleaseTime,
    ): int {
        $axis = $context->request->space->timeAxis;
        if (null === $axis) {
            throw new SlotFlowInvalidArgumentException(
                'Weekly shipment calendar rules require a TimeAxis on the request SlotSpace.',
                [],
            );
        }

        return $this->calendar->nextTime($axis, $earliestReleaseTime);
    }
}
