<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Contracts\SolverInterface;
use Nandan108\SlotFlow\Solvers\GreedyFlowSolver;

/**
 * Executes flows against quantity state via a pluggable solver.
 *
 * @api
 */
final class MovementEngine
{
    public function __construct(
        private readonly SolverInterface $solver = new GreedyFlowSolver(),
    ) {
    }

    /**
     * Execute one flow for one subject.
     *
     * @param array<mixed>               $appContext
     * @param array<string, scalar|null> $params
     */
    public function execute(
        QuantityState $inventory,
        SlotSpace $space,
        string | Flow $cascade,
        int | float $quantity,
        mixed $subject = null,
        array $appContext = [],
        array $params = [],
    ): MovementResult {
        if (is_string($cascade)) {
            $cascade = $space->getFlow($cascade);
        }

        return $this->solver->execute($inventory, $space, $cascade, $quantity, $subject, $appContext, $params);
    }
}
