<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Results\DemandLineSchedule;
use Nandan108\SlotFlow\Results\DemandShipment;

/**
 * Rich order-level context passed to demand release policies and shipment calendars.
 *
 * @api
 */
final class DemandReleaseContext
{
    /**
     * Create one order-level release-evaluation context.
     *
     * @param list<DemandLineSchedule> $lineSchedules
     * @param array<string, int|float> $availableBySubject
     * @param array<string, int|float> $shippedBySubject
     * @param list<DemandShipment>     $shipments
     */
    public function __construct(
        /** Original demand scheduling request being planned. */
        public readonly DemandScheduleRequest $request,
        /** Per-line schedules available to the current planning step. */
        public readonly array $lineSchedules,
        /** Candidate release time currently being evaluated. */
        public readonly int $time,
        /** True when the planner has reached its final release evaluation after all arrivals. */
        public readonly bool $finalEvaluation,
        /** Quantities ready to ship now, keyed by subject key. */
        public readonly array $availableBySubject,
        /** Quantities already shipped by prior planned shipments, keyed by subject key. */
        public readonly array $shippedBySubject,
        /** Shipments already planned before this context evaluation. */
        public readonly array $shipments,
    ) {
    }

    /**
     * Return the currently ready quantity for one subject.
     */
    public function availableQuantity(string $subjectKey): int | float
    {
        return $this->availableBySubject[$subjectKey] ?? 0;
    }

    /**
     * Return the currently ready quantity for one specific demand line.
     */
    public function availableQuantityForLine(DemandLineSchedule $lineSchedule): int | float
    {
        /** @var int|float $available */
        $available = 0;

        foreach ($lineSchedule->arrivals as $arrival) {
            if ($arrival->time > $this->time) {
                break;
            }

            /** @psalm-suppress InvalidOperand */
            $available += $arrival->quantity;
        }

        /** @psalm-suppress InvalidOperand */
        return max(0, $available - $this->shippedQuantityForLine($lineSchedule));
    }

    /**
     * Return the already planned shipped quantity for one subject.
     */
    public function shippedQuantity(string $subjectKey): int | float
    {
        return $this->shippedBySubject[$subjectKey] ?? 0;
    }

    /**
     * Return the already planned shipped quantity for one specific demand line.
     */
    public function shippedQuantityForLine(DemandLineSchedule $lineSchedule): int | float
    {
        /** @var int|float $shipped */
        $shipped = 0;

        foreach ($this->shipments as $shipment) {
            foreach ($shipment->lines as $shipmentLine) {
                if ($shipmentLine->lineSchedule !== $lineSchedule) {
                    continue;
                }

                /** @psalm-suppress InvalidOperand */
                $shipped += $shipmentLine->quantity;
            }
        }

        return $shipped;
    }

    /**
     * Return the originally requested quantity for one specific demand line.
     */
    public function requestedQuantityForLine(DemandLineSchedule $lineSchedule): int | float
    {
        return $lineSchedule->requestedQuantity();
    }

    /**
     * Return the remaining requested quantity for one specific demand line.
     */
    public function remainingQuantityForLine(DemandLineSchedule $lineSchedule): int | float
    {
        /** @psalm-suppress InvalidOperand */
        return max(0, $this->requestedQuantityForLine($lineSchedule) - $this->shippedQuantityForLine($lineSchedule));
    }

    /**
     * Return the total currently ready quantity across all subjects.
     */
    public function totalAvailableQuantity(): int | float
    {
        /** @var int|float $total */
        $total = 0;

        foreach ($this->lineSchedules as $lineSchedule) {
            /** @psalm-suppress InvalidOperand */
            $total += $this->availableQuantityForLine($lineSchedule);
        }

        return $total;
    }

    /**
     * Return the total requested quantity across all demand lines.
     */
    public function totalRequestedQuantity(): int | float
    {
        /** @var int|float $requested */
        $requested = 0;
        foreach ($this->lineSchedules as $lineSchedule) {
            /** @psalm-suppress InvalidOperand */
            $requested += $lineSchedule->requestedQuantity();
        }

        return $requested;
    }

    /**
     * Return the current ready fill ratio across the whole demand.
     */
    public function fillRatio(): float
    {
        $requested = (float) $this->totalRequestedQuantity();

        return 0.0 === $requested ? 0.0 : (float) $this->totalAvailableQuantity() / $requested;
    }
}
