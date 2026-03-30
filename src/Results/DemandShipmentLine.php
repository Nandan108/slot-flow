<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Results;

/**
 * One shipped quantity for one demand line within a demand shipment.
 *
 * @api
 */
final class DemandShipmentLine
{
    /**
     * Create one shipped line quantity within a planned shipment.
     */
    public function __construct(
        /** Subject key of the shipped line. */
        public readonly string $subjectKey,
        /** Quantity released for this line in the shipment. */
        public readonly int | float $quantity,
        /** Source demand line schedule from which this shipment line was derived. */
        public readonly DemandLineSchedule $lineSchedule,
    ) {
    }
}
