<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\DemandReleaseContext;
use Nandan108\SlotFlow\Results\DemandShipmentLine;

/**
 * Builds shipment releases from per-line demand schedules.
 *
 * @api
 */
interface DemandReleasePolicyInterface
{
    /**
     * Select the shipment lines that should release for the current planning context.
     *
     * Policies are evaluated by a shipment planner that decides when to call the
     * policy and how to apply consolidation windows and shipment calendars.
     *
     * @return list<DemandShipmentLine>
     */
    public function release(DemandReleaseContext $context): array;
}
