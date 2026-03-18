<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class MovementEvent
{
    /** @param non-negative-int $quantity */
    public function __construct(
        private MovementEdge $edge,
        private int $quantity,
    ) {
    }

    public function edge(): MovementEdge
    {
        return $this->edge;
    }

    /** @return non-negative-int */
    public function quantity(): int
    {
        return $this->quantity;
    }
}
