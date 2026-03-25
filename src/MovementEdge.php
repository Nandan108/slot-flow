<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * @api
 */
final class MovementEdge
{
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

    public function meta(array $attributes): self
    {
        return new self($this->from, $this->to, $this->label, $attributes + $this->attributes);
    }
}
