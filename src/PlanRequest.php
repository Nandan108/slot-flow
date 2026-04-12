<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * Immutable request for timeless path/allocation planning.
 *
 * @api
 */
class PlanRequest
{
    public readonly Flow $flow;
    public readonly Slot $target;
    /** @var array<string, string> */
    public readonly array $params;

    /**
     * Create one timeless planning request.
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
        array $params = [],
    ) {
        $this->flow = $flow instanceof Flow ? $flow : $space->getFlow($flow);
        $this->target = $space->slot($target);
        $this->params = array_map('strval', $params);
    }
}
