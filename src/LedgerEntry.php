<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class LedgerEntry
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly MovementEdge $edge,
        public readonly int | float $quantity,
        public readonly int | float | null $initialFrom,
        public readonly int | float | null $initialTo,
        public readonly array $context = [],
    ) {
    }

    public function finalFrom(): int | float | null
    {
        if (null === $this->initialFrom) {
            return null;
        }

        /** @psalm-suppress InvalidOperand */
        return $this->initialFrom - $this->quantity;
    }

    public function finalTo(): int | float | null
    {
        if (null === $this->initialTo) {
            return null;
        }

        /** @psalm-suppress InvalidOperand */
        return $this->initialTo + $this->quantity;
    }
}
