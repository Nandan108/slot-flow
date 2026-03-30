<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Time;

use Nandan108\SlotFlow\MovementEdge;

/**
 * Adjusts the earliest dispatch time for a timed movement edge.
 *
 * @api
 */
interface DispatchCalendarInterface
{
    /**
     * Return the earliest allowed dispatch time for the given edge and timed context.
     *
     * Implementations may enforce cutoffs, weekends, holidays, or carrier- and warehouse-specific schedules.
     *
     * The returned value must be greater than or equal to `TimedDurationContext::$earliestDispatchTime`.
     */
    public function dispatchTime(MovementEdge $edge, TimedDurationContext $context): int;
}
