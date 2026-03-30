<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * Immutable request for one planning-oriented schedule computation.
 *
 * @api
 */
final class ScheduleRequest
{
    public readonly Flow $flow;
    public readonly Slot $target;
    /** @var array<string, string> */
    public readonly array $params;
    public readonly int $startTime;

    /**
     * Create one schedule-planning request.
     *
     * @param array<string, scalar|null> $params
     * @param Flow|non-empty-string      $flow
     * @param Slot|non-empty-string      $target
     */
    public function __construct(
        public readonly QuantityState $state,
        public readonly SlotSpace $space,
        Flow | string $flow,
        public readonly int | float $quantity,
        Slot | string $target,
        int | string $startTime = 0,
        array $params = [],
    ) {
        // Resolve the flow from either a Flow instance, a string key, or the space's default flow if only a space is provided.
        $this->flow = $flow instanceof Flow ? $flow : $space->getFlow($flow);

        // Resolve the target slot from either a Slot instance or a string key.
        $this->target = $space->slot($target);

        // Parse the request start time using the space's time axis if available, otherwise default to integer parsing.
        $this->startTime = $space->timeAxis?->parse($startTime) ?? (
            is_int($startTime) ? $startTime : (int) $startTime
        );
        $this->originTime = $this->startTime;

        // Normalize all params to strings for easier downstream handling, since they are primarily intended for use as string keys.
        $this->params = array_map('strval', $params);
    }

    public readonly int $originTime;
}
