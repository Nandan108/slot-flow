<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Results;

/**
 * One planned shipment release containing one or more demand lines.
 *
 * @api
 */
final class DemandShipment
{
    /**
     * Create one planned shipment release.
     *
     * @param list<DemandShipmentLine> $lines
     */
    public function __construct(
        /** Release time for this shipment on the normalized time axis. */
        public readonly int $releaseTime,
        /** Shipment line quantities included in this release. */
        public readonly array $lines,
    ) {
    }

    /**
     * Return the total quantity included in this shipment across all lines.
     */
    public function totalQuantity(): int | float
    {
        /** @var int|float $quantity */
        $quantity = 0;
        foreach ($this->lines as $line) {
            /** @psalm-suppress InvalidOperand */
            $quantity += $line->quantity;
        }

        return $quantity;
    }
}
