<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\MovementSchedule;
use Nandan108\SlotFlow\ScheduleRequest;

/**
 * Computes one planned movement schedule from a planning request.
 *
 * @api
 */
interface ScheduleSolverInterface
{
    /**
     * Build one schedule for the provided planning request.
     */
    public function schedule(ScheduleRequest $request): MovementSchedule;
}
