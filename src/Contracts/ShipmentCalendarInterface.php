<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\DemandReleaseContext;

/**
 * Adjusts planned shipment release times according to order-level operational calendars.
 *
 * @api
 */
interface ShipmentCalendarInterface
{
    /**
     * Return the earliest allowed shipment release time for the given planning context.
     */
    public function releaseTime(DemandReleaseContext $context): int;
}
