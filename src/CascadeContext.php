<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class CascadeContext
{
    /**
     * @param list<MovementEdge> $edges
     * @param array<mixed>       $context
     */
    public function __construct(
        public readonly array $edges,
        public readonly Inventory $inventory,
        public readonly int | float $quantity,
        public readonly mixed $subject = null,
        public readonly array $context = [],
    ) {
    }
}
