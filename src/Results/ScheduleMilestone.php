<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Results;

use Nandan108\SlotFlow\Time\TimedSlot;

/**
 * One named milestone reached by a movement schedule.
 *
 * @api
 */
final class ScheduleMilestone
{
    /**
     * Create one schedule milestone.
     */
    public function __construct(
        public readonly string $name,
        public readonly TimedSlot $slot,
        public readonly int | float $quantity,
        public readonly ?string $scheduleStepId = null,
    ) {
    }
}
