<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class MovementEdge
{
    public function __construct(
        public readonly SlotKey $from,
        public readonly SlotKey $to,
        public readonly ?string $label = null,
        public readonly array $attributes = [],
    ) {
    }

    public function flip(): self
    {
        return new self($this->to, $this->from, $this->label, $this->attributes);
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
