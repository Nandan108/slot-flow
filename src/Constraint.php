<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

interface Constraint
{
    public function limit(
        Inventory $inventory,
        MovementEdge $edge,
        int $requested,
    ): int;
}
