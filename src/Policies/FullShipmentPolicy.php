<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Policies;

use Nandan108\SlotFlow\Contracts\DemandReleasePolicyInterface;
use Nandan108\SlotFlow\DemandReleaseContext;
use Nandan108\SlotFlow\Results\DemandShipmentLine;

/**
 * Waits until all lines are complete, then releases them as one shipment.
 *
 * @api
 */
final class FullShipmentPolicy implements DemandReleasePolicyInterface
{
    /**
     * Release all lines together once every line is fully scheduled.
     *
     * @return list<DemandShipmentLine>
     */
    #[\Override]
    public function release(DemandReleaseContext $context): array
    {
        if ([] === $context->lineSchedules) {
            return [];
        }

        /** @var list<DemandShipmentLine> $shipmentLines */
        $shipmentLines = [];

        foreach ($context->lineSchedules as $lineSchedule) {
            $available = $context->availableQuantityForLine($lineSchedule);
            if (!$lineSchedule->isComplete() || $available < $context->remainingQuantityForLine($lineSchedule)) {
                return [];
            }

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
