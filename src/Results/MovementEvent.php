<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Results;

use Nandan108\SlotFlow\MovementEdge;

/**
 * One applied movement with before-state snapshots for source and destination.
 *
 * @template-covariant TQtty of int|float
 *
 * @api
 */
final class MovementEvent
{
    /**
     * @psalm-param TQtty  $quantity
     * @psalm-param ?TQtty $initialFrom
     * @psalm-param ?TQtty $initialTo
     **/
    public function __construct(
        public readonly MovementEdge $edge,
        public readonly int | float $quantity,
        public readonly int | float | null $initialFrom,
        public readonly int | float | null $initialTo,
    ) {
    }

    /**
     * Return the source quantity after this movement, or null for nil sources.
     */
    public function finalFrom(): int | float | null
    {
        if (null === $this->initialFrom) {
            return null;
        }

        /** @psalm-suppress InvalidOperand */
        return $this->initialFrom - $this->quantity;
    }

    /**
     * Return the destination quantity after this movement, or null for nil sinks.
     */
    public function finalTo(): int | float | null
    {
        if (null === $this->initialTo) {
            return null;
        }

        /** @psalm-suppress InvalidOperand */
        return $this->initialTo + $this->quantity;
    }

    /**
     * Convert this event to per-slot inventory mutations.
     *
     * @return list<InventoryMutation>
     */
    public function mutations(): array
    {
        /** @var list<InventoryMutation> $mutations */
        $mutations = [];

        if (!$this->edge->from->isNil()) {
            $mutations[] = new InventoryMutation($this->edge->from, -$this->quantity);
        }

        if (!$this->edge->to->isNil()) {
            $mutations[] = new InventoryMutation($this->edge->to, $this->quantity);
        }

        return $mutations;
    }

    /**
     * Convert this event to a ledger entry.
     *
     * @param array<string, mixed> $context
     */
    public function ledgerEntry(array $context = []): LedgerEntry
    {
        return new LedgerEntry(
            edge: $this->edge,
            quantity: $this->quantity,
            initialFrom: $this->initialFrom,
            initialTo: $this->initialTo,
            context: $context,
        );
    }
}
