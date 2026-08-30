<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Results\LedgerEntry;
use Nandan108\SlotFlow\Results\MovementEvent;
use Nandan108\SlotFlow\Results\QuantityStateDelta;

/**
 * Immutable summary of one cascade execution.
 *
 * @api
 */
final class MovementResult
{
    /**
     * @param list<MovementEvent> $events
     */
    public function __construct(
        public readonly array $events,
        public readonly int | float $remaining,
    ) {
    }

    /**
     * Return true when the requested quantity was fully satisfied.
     */
    public function isComplete(): bool
    {
        // Quantities are int|float throughout, so the comparison has to be float-safe: a fully
        // satisfied float movement leaves 0.0 remaining, which is not identical to int 0.
        return 0.0 === (float) $this->remaining;
    }

    /**
     * Aggregate per-slot quantity-state deltas for this result.
     *
     * The output is stable in first-seen slot order, which makes it suitable
     * for deterministic persistence and testing.
     *
     * @return list<QuantityStateDelta>
     */
    public function deltas(): array
    {
        /** @var array<string, QuantityStateDelta> $deltasBySlot */
        $deltasBySlot = [];
        /** @var list<string> $slotOrder */
        $slotOrder = [];

        foreach ($this->events as $event) {
            foreach ($event->deltas() as $delta) {
                $slotKey = $delta->slot->key;

                if (!isset($deltasBySlot[$slotKey])) {
                    $deltasBySlot[$slotKey] = $delta;
                    $slotOrder[] = $slotKey;
                    continue;
                }

                $existing = $deltasBySlot[$slotKey];
                /** @psalm-suppress InvalidOperand */
                $mergedDelta = $existing->delta + $delta->delta;

                $deltasBySlot[$slotKey] = new QuantityStateDelta(
                    $existing->slot,
                    $mergedDelta,
                );
            }
        }

        /** @var list<QuantityStateDelta> $deltas */
        $deltas = [];
        foreach ($slotOrder as $slotKey) {
            $delta = $deltasBySlot[$slotKey];
            if (0.0 === (float) $delta->delta) {
                continue;
            }

            $deltas[] = $delta;
        }

        return $deltas;
    }

    /**
     * Return a copy of the given quantity state with this result's net deltas applied.
     *
     * Execution computes movement without touching the state it was handed, so applying a result
     * is an explicit step the caller takes when it wants the resulting state rather than the
     * ledger. The input is left untouched, which is what makes a result safe to hold, inspect,
     * compare against alternatives, or discard.
     */
    public function applyTo(QuantityState $state): QuantityState
    {
        $updated = $state->copy();

        foreach ($this->deltas() as $delta) {
            $updated->add($delta->slot, $delta->delta);
        }

        return $updated;
    }

    /**
     * Convert each movement event to a ledger entry.
     *
     * @param array<string, mixed> $context
     *
     * @return list<LedgerEntry>
     */
    public function ledgerEntries(array $context = []): array
    {
        return array_map(
            static fn (MovementEvent $event): LedgerEntry => $event->ledgerEntry($context),
            $this->events,
        );
    }
}
