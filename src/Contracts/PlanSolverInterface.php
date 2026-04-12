<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\MovementPlan;
use Nandan108\SlotFlow\PlanRequest;

/**
 * Computes one timeless movement plan from a planning request.
 *
 * @api
 */
interface PlanSolverInterface
{
    /**
     * Build one timeless plan for the provided planning request.
     */
    public function plan(PlanRequest $request): MovementPlan;
}
