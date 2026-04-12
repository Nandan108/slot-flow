<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Results\PlannedStep;
use Nandan108\SlotFlow\Results\QuantityStateDelta;

/**
 * Immutable summary of one timeless movement plan.
 *
 * @api
 */
final class MovementPlan
{
    /**
     * Create one timeless movement plan result.
     *
     * @param list<PlannedStep> $steps
     */
    public function __construct(
        public readonly array $steps,
        public readonly int | float $remaining,
    ) {
    }

    /**
     * Return true when the requested quantity is fully planned.
     */
    public function isComplete(): bool
    {
        return 0.0 === (float) $this->remaining;
    }

    /**
     * Aggregate per-slot quantity-state deltas for this plan.
     *
     * @return list<QuantityStateDelta>
     */
    public function deltas(): array
    {
        /** @var array<string, QuantityStateDelta> $deltasBySlot */
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
                $deltasBySlot[$slotKey] = new QuantityStateDelta($existing->slot, $mergedDelta);
            }
        }

        /** @var list<QuantityStateDelta> $deltas */
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
     * Find one planned step by id.
     */
    public function step(string $id): ?PlannedStep
    {
        foreach ($this->steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        return null;
    }
}
