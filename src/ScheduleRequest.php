<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;

/**
 * Immutable request for one planning-oriented schedule computation.
 *
 * @api
 */
final class ScheduleRequest extends PlanRequest
{
    public readonly int $startTime;

    /**
     * Create one schedule-planning request.
     *
     * @param array<string, scalar|null> $params
     * @param Flow|non-empty-string      $flow
     * @param Slot|non-empty-string      $target
     */
    public function __construct(
        QuantityState $state,
        SlotSpace $space,
        Flow | string $flow,
        int | float $quantity,
        Slot | string $target,
        \DateTimeImmutable | int | string $startTime = 0,
        array $params = [],
    ) {
        parent::__construct($state, $space, $flow, $quantity, $target, $params);

        if (null === $space->timeAxis) {
            throw new SlotFlowInvalidArgumentException(
                'Schedule requests require a TimeAxis on the SlotSpace.',
                ['start_time' => $startTime],
            );
        }

        $this->startTime = $space->timeAxis->parse($startTime);
        $this->originTime = $this->startTime;
    }

    public readonly int $originTime;
}
