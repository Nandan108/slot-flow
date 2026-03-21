<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * @template TQtty of int|float
 */
final class MovementEvent
{
    /**
     * @psalm-param TQtty  $quantity
     * @psalm-param ?TQtty $initialFrom
     * @psalm-param ?TQtty $initialTo
     **/
    public function __construct(
        private MovementEdge $edge,
        private int | float $quantity,
        private int | float | null $initialFrom,
        private int | float | null $initialTo,
    ) {
    }

    public function edge(): MovementEdge
    {
        return $this->edge;
    }

    public function initialFrom(): int | float | null
    {
        return $this->initialFrom;
    }

    public function initialTo(): int | float | null
    {
        return $this->initialTo;
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

    /**
     * @psalm-return TQtty
     */
    public function quantity(): int | float
    {
        return $this->quantity;
    }
}
