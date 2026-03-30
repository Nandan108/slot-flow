<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Time;

use Nandan108\SlotFlow\MovementEdge;

/**
 * Dispatch calendar that delays departures until the next matching weekly dispatch moment.
 *
 * @api
 */
final class WeeklyDispatchCalendar implements DispatchCalendarInterface
{
    /**
     * Create one weekly dispatch calendar around a reusable weekly schedule.
     */
    public function __construct(
        public readonly WeeklyCalendar $calendar,
    ) {
    }

    /**
     * Return the first dispatch time at or after the edge's earliest dispatch time that matches the weekly schedule.
     */
    #[\Override]
    public function dispatchTime(MovementEdge $edge, TimedDurationContext $context): int
    {
        return $this->calendar->nextTime($context->axis, $context->earliestDispatchTime);
    }
}
