<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Results;

use Nandan108\SlotFlow\DemandLine;
use Nandan108\SlotFlow\MovementSchedule;
use Nandan108\SlotFlow\Slot;

/**
 * One demand line plus its computed movement schedule and arrival timeline.
 *
 * @api
 */
final class DemandLineSchedule
{
    /**
     * Create one scheduled demand line result.
     *
     * @param list<DemandLineArrival> $arrivals
     */
    public function __construct(
        /** Original requested demand line. */
        public readonly DemandLine $line,
        /** Normalized subject key used to match state and shipment lines. */
        public readonly string $subjectKey,
        /** Underlying movement schedule for this line. */
        public readonly MovementSchedule $schedule,
        /** Final target slot this line is being scheduled toward. */
        public readonly Slot $target,
        /** Quantity arrivals at the target over time. */
        public readonly array $arrivals,
    ) {
    }

    /**
     * Return the quantity originally requested for this line.
     */
    public function requestedQuantity(): int | float
    {
        return $this->line->quantity;
    }

    /**
     * Return the quantity currently scheduled to reach the target.
     */
    public function fulfilledQuantity(): int | float
    {
        /** @var int|float $fulfilled */
        $fulfilled = 0;
        foreach ($this->arrivals as $arrival) {
            /** @psalm-suppress InvalidOperand */
            $fulfilled += $arrival->quantity;
        }

        return $fulfilled;
    }

    /**
     * Return the quantity not yet scheduled for the line.
     */
    public function remainingQuantity(): int | float
    {
        return $this->schedule->remaining;
    }

    /**
     * Return true when the line quantity is fully scheduled.
     */
    public function isComplete(): bool
    {
        return $this->schedule->isComplete();
    }

    /**
     * Return the first time any quantity becomes available for this line, if any.
     */
    public function firstReadyTime(): ?int
    {
        return $this->arrivals[0]->time ?? null;
    }

    /**
     * Return the final time at which this line becomes fully available, if any.
     */
    public function completeTime(): ?int
    {
        if ([] === $this->arrivals) {
            return null;
        }

        return $this->arrivals[array_key_last($this->arrivals)]->time;
    }

    /**
     * Return terminal scheduled steps that have arrived at the target by the given time.
     *
     * @return list<ScheduledStep>
     */
    public function readyArrivalSteps(int $time): array
    {
        return array_values(array_filter(
            $this->schedule->steps,
            fn (ScheduledStep $step): bool => $step->edge->to->slot->key === $this->target->key && $step->arrivalTime() <= $time,
        ));
    }

    /**
     * Return the terminal arrival steps that contribute the next released quantity slice.
     *
     * @return list<ScheduledStep>
     */
    public function releasedArrivalSteps(int $time, int | float $alreadyShipped, int | float $releaseQuantity): array
    {
        /** @var list<ScheduledStep> $selected */
        $selected = [];
        /** @var int|float $offset */
        $offset = 0;
        $remaining = $releaseQuantity;

        foreach ($this->readyArrivalSteps($time) as $step) {
            /** @psalm-suppress InvalidOperand */
            $stepStart = $offset;
            /** @psalm-suppress InvalidOperand */
            $stepEnd = $offset + $step->quantity;
            $offset = $stepEnd;

            if ($stepEnd <= $alreadyShipped) {
                continue;
            }

            $selected[] = $step;
            /** @psalm-suppress InvalidOperand */
            $remaining -= min($stepEnd - max($stepStart, $alreadyShipped), $remaining);
            if ($remaining <= 0) {
                break;
            }
        }

        return $selected;
    }
}
