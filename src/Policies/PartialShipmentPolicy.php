<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Policies;

use Nandan108\SlotFlow\Contracts\DemandReleasePolicyInterface;
use Nandan108\SlotFlow\DemandReleaseContext;
use Nandan108\SlotFlow\Results\DemandShipmentLine;

/**
 * Releases every ready quantity at the earliest distinct ready time.
 *
 * @api
 */
final class PartialShipmentPolicy implements DemandReleasePolicyInterface
{
    /**
     * Release every currently ready quantity immediately.
     *
     * @return list<DemandShipmentLine>
     */
    #[\Override]
    public function release(DemandReleaseContext $context): array
    {
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
