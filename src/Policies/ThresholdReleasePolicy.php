<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Policies;

use Nandan108\SlotFlow\Contracts\DemandReleasePolicyInterface;
use Nandan108\SlotFlow\DemandReleaseContext;
use Nandan108\SlotFlow\Results\DemandShipmentLine;

/**
 * Releases accumulated ready quantities once a configured fill threshold is reached.
 *
 * @api
 */
final class ThresholdReleasePolicy implements DemandReleasePolicyInterface
{
    /**
     * Create one threshold-based release policy.
     */
    public function __construct(
        /** Minimum fill ratio required before accumulated ready quantities may ship. */
        private readonly float $minFillRatio = 0.0,
        /** Minimum absolute ready quantity required before a shipment may release. */
        private readonly int | float $minReadyQuantity = 0,
    ) {
    }

    /**
     * Release accumulated ready quantities whenever the configured thresholds are met.
     *
     * @return list<DemandShipmentLine>
     */
    #[\Override]
    public function release(DemandReleaseContext $context): array
    {
        $readyQuantity = $context->totalAvailableQuantity();
        $fillRatio = $context->fillRatio();
        if (
            $readyQuantity <= 0
            || (
                !$context->finalEvaluation
                && ($readyQuantity < $this->minReadyQuantity || $fillRatio < $this->minFillRatio)
            )
        ) {
            return [];
        }

        /** @var list<DemandShipmentLine> $shipmentLines */
        $shipmentLines = [];
        foreach ($context->lineSchedules as $lineSchedule) {
            $available = $context->availableQuantityForLine($lineSchedule);
            if ($available <= 0) {
                continue;
            }

            $shipmentLines[] = new DemandShipmentLine(
                subjectKey: $lineSchedule->subjectKey,
                quantity: $available,
                lineSchedule: $lineSchedule,
            );
        }

        return $shipmentLines;
    }
}
