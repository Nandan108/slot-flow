<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * One directed movement edge between two slots.
 *
 * @api
 */
final class MovementEdge
{
    /**
     * Create a directed edge between two slots.
     */
    public function __construct(
        public readonly Slot $from,
        public readonly Slot $to,
        public readonly ?string $label = null,
        public readonly array $attributes = [],
    ) {
    }

    public function __toString(): string
    {
        return "($this->from) -> ($this->to)";
    }

    /**
     * Create a copy of the edge with merged metadata.
     */
    public function meta(array $attributes): self
    {
        return new self($this->from, $this->to, $this->label, $attributes + $this->attributes);
    }
}
