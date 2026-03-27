<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Time;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Slot;

/**
 * Quantity distribution over timed slots.
 *
 * @api
 */
final class TimedQuantityState
{
    /** @var array<string, int|float> */
    private array $quantities = [];

    /**
     * Create one timed quantity state from timed-slot quantity tuples.
     *
     * @param list<array{0: TimedSlot|Slot|string, 1: int|float, 2?: int|string|null}> $tuples
     */
    public function __construct(
        public readonly TimedSlotSpace $space,
        array $tuples = [],
    ) {
        foreach ($tuples as $tuple) {
            [$slot, $quantity, $time] = $tuple + [null, null, null];
            $resolved = $this->resolveTimedSlot($slot, $time);
            $this->quantities[$resolved->key] = $quantity;
        }
    }

    /**
     * Expand a base QuantityState into one timed state at the given origin time.
     */
    public static function fromQuantityState(
        TimedSlotSpace $space,
        QuantityState $state,
        int | string $time = 0,
    ): self {
        $tuples = [];
        foreach ($state->all() as $slotKey => $quantity) {
            $tuples[] = [$slotKey, $quantity, $time];
        }

        return new self($space, $tuples);
    }

    /**
     * Return the quantity currently stored at one timed slot, defaulting to zero.
     */
    public function get(TimedSlot | string $slot): int | float
    {
        $slot = is_string($slot) ? $this->space->slot($slot) : $slot;

        return $this->quantities[$slot->key] ?? 0;
    }

    /**
     * Add a signed quantity delta to one timed slot.
     */
    public function add(TimedSlot $slot, int | float $delta): void
    {
        $current = $this->quantities[$slot->key] ?? 0;

        $this->quantities[$slot->key] = \is_float($current) || \is_float($delta)
            ? (float) $current + (float) $delta
            : $current + $delta;
    }

    /**
     * Return all stored timed-slot quantities keyed by canonical `slot@time`.
     *
     * @return array<string, int|float>
     */
    public function all(): array
    {
        return $this->quantities;
    }

    /**
     * Return a shallow copy of this timed quantity state.
     */
    public function copy(): self
    {
        $copy = new self($this->space);
        $copy->quantities = $this->quantities;

        return $copy;
    }

    /**
     * Resolve constructor tuple input into one concrete timed slot instance.
     */
    private function resolveTimedSlot(TimedSlot | Slot | string | null $slot, int | string | null $time): TimedSlot
    {
        if ($slot instanceof TimedSlot) {
            return $slot;
        }

        if (null === $time) {
            if (!is_string($slot) || !str_contains($slot, '@')) {
                throw new SlotFlowInvalidArgumentException(
                    'Timed slot tuples must provide either a TimedSlot, a serialized timed key, or a separate time value.',
                    ['slot' => $slot, 'time' => $time],
                );
            }

            return $this->space->slot($slot);
        }

        if ($slot instanceof Slot) {
            return $this->space->slot($slot, $time);
        }

        return $this->space->slot((string) $slot, $time);
    }
}
