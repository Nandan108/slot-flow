<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Time;

use Nandan108\SlotFlow\MovementEdge;

/**
 * Context passed to timed duration resolvers.
 *
 * @api
 */
final class TimedDurationContext
{
    public function __construct(
        public readonly TimedSlotSpace $space,
        public readonly TimeAxis $axis,
        public readonly TimedSlot $from,
        public readonly MovementEdge $edge,
        public readonly int $earliestDispatchTime,
    ) {
    }
}
