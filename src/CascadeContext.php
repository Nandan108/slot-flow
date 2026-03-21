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

    /**
     * @return array<string, mixed>
     */
    public function slotAttributes(Slot $slot): array
    {
        return $this->inventory->slotAttributes($slot);
    }

    public function slotAttribute(Slot $slot, string $name, mixed $default = null): mixed
    {
        return $this->inventory->slotAttribute($slot, $name, $default);
    }
}
