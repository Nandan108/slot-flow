<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Results;

use Nandan108\SlotFlow\Time\TimedMovementEdge;

/**
 * One scheduled timed movement.
 *
 * @api
 */
final class ScheduledStep
{
    /**
     * Create one scheduled movement step.
     */
    public function __construct(
        public readonly string $id,
        public readonly TimedMovementEdge $edge,
        public readonly int | float $quantity,
    ) {
    }

    /**
     * Return the scheduled departure time index.
     */
    public function departureTime(): int
    {
        return $this->edge->from->timeIndex;
    }

    /**
     * Return the scheduled arrival time index.
     */
    public function arrivalTime(): int
    {
        return $this->edge->to->timeIndex;
    }

    /**
     * Return the scheduled duration in canonical bucket count.
     */
    public function duration(): int
    {
        /** @var int $duration */
        $duration = $this->edge->attributes['duration'] ?? 0;

        return $duration;
    }

    /**
     * Convert this scheduled step into timed quantity-state deltas.
     *
     * @return list<TimedQuantityStateDelta>
     */
    public function deltas(): array
    {
        /** @var list<TimedQuantityStateDelta> $deltas */
        $deltas = [];

        if (!$this->edge->from->isNil()) {
            $deltas[] = new TimedQuantityStateDelta($this->edge->from, -$this->quantity);
        }

        if (!$this->edge->to->isNil()) {
            $deltas[] = new TimedQuantityStateDelta($this->edge->to, $this->quantity);
        }

        return $deltas;
    }

    /**
     * Return the arrival milestone implied by this scheduled step.
     */
    public function milestone(?string $name = null): ScheduleMilestone
    {
        $label = $this->edge->label;

        return new ScheduleMilestone(
            name: $name ?? ((null !== $label && '' !== $label) ? 'arrive:'.$label : 'arrive'),
            slot: $this->edge->to,
            quantity: $this->quantity,
            scheduleStepId: $this->id,
        );
    }

    /**
     * Return a copy of this step with a different scheduled quantity.
     */
    public function withQuantity(int | float $quantity): self
    {
        return new self($this->id, $this->edge, $quantity);
    }
}
