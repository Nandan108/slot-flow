<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Batch;

use Nandan108\SlotFlow\MovementEdge;

/**
 * Ledger entry paired with the batch subject it belongs to.
 *
 * @template TSubject
 *
 * @api
 */
final class BatchLedgerEntry
{
    /**
     * Create one batch-scoped ledger entry.
     *
     * @param TSubject             $subject
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly mixed $subject,
        public readonly MovementEdge $edge,
        public readonly int | float $quantity,
        public readonly int | float | null $initialFrom,
        public readonly int | float | null $initialTo,
        public readonly array $context = [],
    ) {
    }

    /**
     * Return the source quantity after this ledgered batch movement, or null for nil sources.
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
     * Return the destination quantity after this ledgered batch movement, or null for nil sinks.
     */
    public function finalTo(): int | float | null
    {
        if (null === $this->initialTo) {
            return null;
        }

        /** @psalm-suppress InvalidOperand */
        return $this->initialTo + $this->quantity;
    }
}
