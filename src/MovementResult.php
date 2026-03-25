<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Results\InventoryMutation;
use Nandan108\SlotFlow\Results\LedgerEntry;
use Nandan108\SlotFlow\Results\MovementEvent;

/**
 * Immutable summary of one cascade execution.
 *
 * @template-covariant TQtty of int|float
 *
 * @api
 */
final class MovementResult
{
    /**
     * @param list<MovementEvent> $events
     *
     * @psalm-param list<MovementEvent<TQtty>> $events
     * @psalm-param TQtty                      $remaining
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
     * Aggregate per-slot inventory mutations for this result.
     *
     * The output is stable in first-seen slot order, which makes it suitable
     * for deterministic persistence and testing.
     *
     * @return list<InventoryMutation>
     */
    public function mutations(): array
    {
        /** @var array<string, InventoryMutation> $mutationsBySlot */
        $mutationsBySlot = [];
        /** @var list<string> $slotOrder */
        $slotOrder = [];

        foreach ($this->events as $event) {
            foreach ($event->mutations() as $mutation) {
                $slotKey = $mutation->slot->key;

                if (!isset($mutationsBySlot[$slotKey])) {
                    $mutationsBySlot[$slotKey] = $mutation;
                    $slotOrder[] = $slotKey;
                    continue;
                }

                $existing = $mutationsBySlot[$slotKey];
                /** @psalm-suppress InvalidOperand */
                $delta = $existing->delta + $mutation->delta;

                $mutationsBySlot[$slotKey] = new InventoryMutation(
                    $existing->slot,
                    $delta,
                );
            }
        }

        /** @var list<InventoryMutation> $mutations */
        $mutations = [];
        foreach ($slotOrder as $slotKey) {
            $mutation = $mutationsBySlot[$slotKey];
            if (0 === $mutation->delta) {
                continue;
            }

            $mutations[] = $mutation;
        }

        return $mutations;
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
