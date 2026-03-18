<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Constraints;

use Nandan108\SlotFlow as SF;

/**
 * @template TQtty of int|float
 *
 * @implements SF\Contracts\Constraint<TQtty>
 */
final class MaxSlotCapacity implements SF\Contracts\Constraint
{
    public function __construct(
        private SF\SlotKey $slot,
        private int | float $capacity,
    ) {
    }

    /**
     * @param TQtty $requested
     *
     * @return TQtty
     */
    #[\Override]
    public function limit(
        SF\Inventory $inventory,
        SF\MovementEdge $edge,
        int | float $requested,
    ): int | float {
        if (!$edge->to || !$edge->to->equals($this->slot)) {
            return $requested;
        }

        $current = $inventory->get($this->slot);

        /** @psalm-suppress InvalidOperand */
        /** @var TQtty */
        return max(0, min($requested, $this->capacity - $current));
    }
}
