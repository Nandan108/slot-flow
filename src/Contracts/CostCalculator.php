<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\MovementEdge;

interface CostCalculator
{
    public function cost(MovementEdge $edge, int $quantity): float;
}
