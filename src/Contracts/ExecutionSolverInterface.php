<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\SlotSpace;

/**
 * Executes one flow against one quantity state.
 *
 * @api
 */
interface ExecutionSolverInterface
{
    /**
     * @param array<mixed>               $appContext
     * @param array<string, scalar|null> $params
     */
    public function execute(
        QuantityState $state,
        SlotSpace $space,
        Flow $flow,
        int | float $quantity,
        mixed $subject = null,
        array $appContext = [],
        array $params = [],
    ): MovementResult;
}
