<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class InventoryMutation
{
    public function __construct(
        public readonly Slot $slot,
        public readonly int | float $delta,
    ) {
    }
}
