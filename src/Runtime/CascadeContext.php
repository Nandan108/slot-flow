<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Runtime;

use Nandan108\SlotFlow\Inventory;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Slot;
use Nandan108\SlotFlow\SlotSpace;

/**
 * Runtime context object passed to policies during one cascade step.
 *
 * @api
 */
final class CascadeContext
{
    /**
     * @param list<MovementEdge> $edges
     * @param array<mixed>       $context
     */
    public function __construct(
        public readonly SlotSpace $space,
        public readonly array $edges,
        public readonly Inventory $inventory,
        public readonly int | float $quantity,
        public readonly mixed $subject = null,
        public readonly array $context = [],
    ) {
    }

    /**
     * Return remembered attributes for one slot in the current inventory.
     *
     * @return array<string, mixed>
     */
    public function slotAttributes(Slot $slot): array
    {
        return $this->inventory->slotAttributes($slot);
    }

    /**
     * Read one remembered slot attribute with an optional default.
     */
    public function slotAttribute(Slot $slot, string $name, mixed $default = null): mixed
    {
        return $this->inventory->slotAttribute($slot, $name, $default);
    }
}
