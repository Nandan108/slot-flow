<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Results;

use Nandan108\SlotFlow\Time\TimedSlot;

/**
 * Net quantity change for one timed slot.
 *
 * @api
 */
final class TimedQuantityStateDelta
{
    /**
     * Create one timed quantity-state delta.
     */
    public function __construct(
        public readonly TimedSlot $slot,
        public readonly int | float $delta,
    ) {
    }
}
