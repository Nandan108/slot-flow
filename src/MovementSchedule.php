<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Results\ScheduledStep;
use Nandan108\SlotFlow\Results\ScheduleMilestone;
use Nandan108\SlotFlow\Results\TimedQuantityStateDelta;

/**
 * Immutable summary of one planned movement schedule over time.
 *
 * @api
 */
final class MovementSchedule
{
    /**
     * @param list<ScheduledStep>     $steps
     * @param list<ScheduleMilestone> $milestones
     */
    public function __construct(
        public readonly array $steps,
        public readonly int | float $remaining,
        public readonly array $milestones = [],
    ) {
    }

    /**
     * Return true when the requested quantity is fully scheduled.
     */
    public function isComplete(): bool
    {
        return 0 === $this->remaining;
    }

    /**
     * Aggregate timed quantity-state deltas for this schedule.
     *
     * @return list<TimedQuantityStateDelta>
     */
    public function deltas(): array
    {
        /** @var array<string, TimedQuantityStateDelta> $deltasBySlot */
        $deltasBySlot = [];
        /** @var list<string> $slotOrder */
        $slotOrder = [];

        foreach ($this->steps as $step) {
            foreach ($step->deltas() as $delta) {
                $slotKey = $delta->slot->key;

                if (!isset($deltasBySlot[$slotKey])) {
                    $deltasBySlot[$slotKey] = $delta;
                    $slotOrder[] = $slotKey;
                    continue;
                }

                $existing = $deltasBySlot[$slotKey];
                /** @psalm-suppress InvalidOperand */
                $mergedDelta = $existing->delta + $delta->delta;
                $deltasBySlot[$slotKey] = new TimedQuantityStateDelta($existing->slot, $mergedDelta);
            }
        }

        /** @var list<TimedQuantityStateDelta> $deltas */
        $deltas = [];
        foreach ($slotOrder as $slotKey) {
            $delta = $deltasBySlot[$slotKey];
            if (0 === $delta->delta) {
                continue;
            }

            $deltas[] = $delta;
        }

        return $deltas;
    }

    /**
     * Return the first milestone reached by this schedule, if any.
     */
    public function firstMilestone(): ?ScheduleMilestone
    {
        return $this->milestones[0] ?? null;
    }

    /**
     * Return the final milestone reached by this schedule, if any.
     */
    public function lastMilestone(): ?ScheduleMilestone
    {
        if ([] === $this->milestones) {
            return null;
        }

        return $this->milestones[array_key_last($this->milestones)];
    }

    /**
     * Find one scheduled step by id.
     */
    public function step(string $id): ?ScheduledStep
    {
        foreach ($this->steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        return null;
    }
}
