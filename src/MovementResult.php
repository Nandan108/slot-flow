<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * @template TQtty of int|float
 */
final class MovementResult
{
    /**
     * @param list<MovementEvent<TQtty>> $events
     * @param TQtty                      $remaining
     */
    public function __construct(
        private array $events,
        private int | float $remaining,
    ) {
    }

    /** @return list<MovementEvent<TQtty>> */
    public function events(): array
    {
        return $this->events;
    }

    /** @return TQtty */
    public function remaining(): int | float
    {
        return $this->remaining;
    }

    public function isComplete(): bool
    {
        return 0 === $this->remaining;
    }
}
