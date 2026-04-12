<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\DemandReleaseContext;
use Nandan108\SlotFlow\DemandScheduleRequest;
use Nandan108\SlotFlow\Results\DemandLineSchedule;
use Nandan108\SlotFlow\Results\DemandShipment;

/**
 * Builds shipment plans from per-line demand schedules.
 *
 * @api
 */
interface ShipmentPlannerInterface
{
    /**
     * Build planned shipments from the provided per-line schedules.
     *
     * @param list<DemandLineSchedule> $lineSchedules
     *
     * @return list<DemandShipment>
     */
    public function plan(DemandScheduleRequest $request, array $lineSchedules): array;

    /**
     * Build one release-policy context for the given planning moment.
     *
     * @param list<DemandLineSchedule> $lineSchedules
     * @param array<string, int|float> $availableBySubject
     * @param array<string, int|float> $shippedBySubject
     * @param list<DemandShipment>     $shipments
     */
    public function context(
        DemandScheduleRequest $request,
        array $lineSchedules,
        int $time,
        bool $finalEvaluation,
        array $availableBySubject,
        array $shippedBySubject,
        array $shipments,
    ): DemandReleaseContext;
}
