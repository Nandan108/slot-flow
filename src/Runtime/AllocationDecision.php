<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Runtime;

use Nandan108\SlotFlow\MovementEdge;

/**
 * @api
 */
final class AllocationDecision
{
    public function __construct(
        public readonly MovementEdge $edge,
        public readonly int | float $quantity,
    ) {
    }
}
