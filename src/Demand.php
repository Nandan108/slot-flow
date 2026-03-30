<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * Multi-line demand to be promised or scheduled together.
 *
 * @api
 */
final class Demand
{
    /**
     * Create one multi-line demand.
     *
     * @param list<DemandLine> $lines
     */
    public function __construct(
        /** Requested order lines to plan together. */
        public readonly array $lines,
    ) {
    }
}
