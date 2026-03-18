<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow as SF;

interface MovementPlanner
{
    public function plan(
        SF\Inventory $inventory,
        SF\SlotKey $from,
        SF\SlotKey $to,
        int $quantity,
    ): SF\MovementPlan;
}
