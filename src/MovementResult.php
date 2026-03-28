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
        return 0 === $this->remaining;
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
            if (0 === $delta->delta) {
                continue;
            }

            $deltas[] = $delta;
        }

        return $deltas;
    }

    /**
     * @deprecated use deltas() instead
     *
     * @return list<QuantityStateDelta>
     */
    public function mutations(): array
    {
        return $this->deltas();
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
