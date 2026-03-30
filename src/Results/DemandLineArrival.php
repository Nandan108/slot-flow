<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Results;

/**
 * One quantity becoming available for a demand line at a given time.
 *
 * @api
 */
final class DemandLineArrival
{
    /**
     * Create one line-availability event.
     */
    public function __construct(
        /** Time index at which the quantity becomes available at the target. */
        public readonly int $time,
        /** Quantity that becomes available at that time. */
        public readonly int | float $quantity,
    ) {
    }
}
