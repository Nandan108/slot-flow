<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Time;

use Nandan108\SlotFlow\MovementEdge;

/**
 * One directed movement edge between two timed slots.
 *
 * @api
 */
final class TimedMovementEdge
{
    /**
     * Create one directed edge between two timed slots, optionally linked to its base edge.
     */
    public function __construct(
        public readonly TimedSlot $from,
        public readonly TimedSlot $to,
        public readonly ?MovementEdge $baseEdge = null,
        public readonly ?string $label = null,
        public readonly array $attributes = [],
    ) {
    }

    /**
     * Return a readable edge representation using timed slot keys.
     */
    public function __toString(): string
    {
        return "($this->from) -> ($this->to)";
    }
}
