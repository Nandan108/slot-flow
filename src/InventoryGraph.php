<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class InventoryGraph
{
    private array $edges = [];

    public static function build(): self
    {
        return new self();
    }

    public function edge(?SlotKey $from, ?SlotKey $to): self
    {
        $clone = clone $this;
        $clone->edges[] = new MovementEdge($from, $to);

        return $clone;
    }

    public function edges(): array
    {
        return $this->edges;
    }
}
