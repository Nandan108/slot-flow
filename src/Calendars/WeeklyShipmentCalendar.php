<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Calendars;

use Nandan108\SlotFlow\Contracts\ShipmentCalendarInterface;
use Nandan108\SlotFlow\DemandReleaseContext;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Time\WeeklyCalendar;

/**
 * Order-level shipment calendar backed by a reusable weekly schedule.
 *
 * @api
 */
final class WeeklyShipmentCalendar implements ShipmentCalendarInterface
{
    /**
     * Create one order-level weekly shipment calendar.
     */
    public function __construct(
        public readonly WeeklyCalendar $calendar,
    ) {
    }

    /**
     * Return the first release time at or after the current planning time that matches the configured weekly schedule.
     *
     * @throws SlotFlowInvalidArgumentException
     */
    #[\Override]
    public function releaseTime(DemandReleaseContext $context): int
    {
        $axis = $context->request->space->timeAxis;
        if (null === $axis) {
            throw new SlotFlowInvalidArgumentException(
                'Weekly shipment calendars require a TimeAxis on the request SlotSpace.',
                [],
            );
        }

        return $this->calendar->nextTime($axis, $context->time);
    }
}
