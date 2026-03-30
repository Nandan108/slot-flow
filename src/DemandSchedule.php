<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Results\DemandLineSchedule;
use Nandan108\SlotFlow\Results\DemandShipment;

/**
 * Immutable summary of one multi-line demand scheduling run.
 *
 * @api
 */
final class DemandSchedule
{
    /**
     * Create one multi-line demand schedule result.
     *
     * @param list<DemandLineSchedule> $lines
     * @param list<DemandShipment>     $shipments
     */
    public function __construct(
        /** Per-line schedules for the requested demand. */
        public readonly array $lines,
        /** Planned shipment releases derived from the line schedules. */
        public readonly array $shipments,
    ) {
    }

    /**
     * Return true when every demand line is fully scheduled.
     */
    public function isComplete(): bool
    {
        foreach ($this->lines as $line) {
            if (!$line->isComplete()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return the first planned shipment release time, if any.
     */
    public function firstShipmentTime(): ?int
    {
        return $this->shipments[0]->releaseTime ?? null;
    }

    /**
     * Return the final planned shipment release time, if any.
     */
    public function completeTime(): ?int
    {
        if ([] === $this->shipments) {
            return null;
        }

        return $this->shipments[array_key_last($this->shipments)]->releaseTime;
    }
}
