<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Results;

use Nandan108\SlotFlow\Slot;

/**
 * @api
 */
final class InventoryMutation
{
    public function __construct(
        public readonly Slot $slot,
        public readonly int | float $delta,
    ) {
    }
}
