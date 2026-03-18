<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class SlotMutation
{
    public function __construct(
        private SlotKey $slot,
        private int $delta,
    ) {
    }

    public function slot(): SlotKey
    {
        return $this->slot;
    }

    public function delta(): int
    {
        return $this->delta;
    }
}
