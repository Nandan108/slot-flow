<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow as SF;

/**
 * @template TQtty of int|float
 */
interface Constraint
{
    /**
     * @param TQtty $requested
     *
     * @return TQtty
     */
    public function limit(
        SF\Inventory $inventory,
        SF\MovementEdge $edge,
        int | float $requested,
    ): int | float;
}
