<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class MovementEdge
{
    public function __construct(
        public readonly ?SlotKey $from,
        public readonly ?SlotKey $to,
    ) {
    }

    public function flip(): self
    {
        return new self($this->to, $this->from);
    }
}
