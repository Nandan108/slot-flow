<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Contracts\PlanSolverInterface;
use Nandan108\SlotFlow\Solvers\GreedyPlanSolver;

/**
 * Builds timeless movement plans via a pluggable solver.
 *
 * @api
 */
final class MovementPlanner
{
    /**
     * Create one movement planner around the provided plan solver.
     */
    public function __construct(
        private readonly PlanSolverInterface $solver = new GreedyPlanSolver(),
    ) {
    }

    /**
     * Build one timeless plan to the requested target.
     *
     * @param array<string, scalar|null> $params
     * @param Flow|non-empty-string      $flow
     * @param Slot|non-empty-string      $target
     */
    public function plan(
        QuantityState $inventory,
        SlotSpace $space,
        string | Flow $flow,
        int | float $quantity,
        Slot | string $target,
        array $params = [],
    ): MovementPlan {
        return $this->solver->plan(new PlanRequest(
            state: $inventory,
            space: $space,
            flow: $flow,
            quantity: $quantity,
            target: $target,
            params: $params,
        ));
    }
}
