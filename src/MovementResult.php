<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class MovementResult
{
    /**
     * @param list<SlotMutation>  $mutations
     * @param list<MovementEvent> $events
     * @param non-negative-int    $remaining
     */
    public function __construct(
        private array $mutations,
        private array $events,
        private int $remaining,
    ) {
    }

    /** @return list<SlotMutation> */
    public function mutations(): array
    {
        return $this->mutations;
    }

    /** @return list<MovementEvent> */
    public function events(): array
    {
        return $this->events;
    }

    /** @return non-negative-int */
    public function remaining(): int
    {
        return $this->remaining;
    }

    public function isComplete(): bool
    {
        return 0 === $this->remaining;
    }
}
