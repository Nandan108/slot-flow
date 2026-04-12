<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Time;

use Nandan108\SlotFlow\Slot;

/**
 * One slot at one point on a discrete time axis.
 *
 * @api
 */
final class TimedSlot
{
    public readonly string $key;

    /**
     * Create one timed slot from a base slot and any accepted time expression.
     */
    public function __construct(
        public readonly Slot $slot,
        public readonly int $timeIndex,
        public readonly string $timeKey,
        public readonly TimedSlotSpace $space,
    ) {
        $this->key = $slot->key.'@'.$timeKey;
    }

    /**
     * Return true when the wrapped base slot is the nil boundary slot.
     */
    public function isNil(): bool
    {
        return $this->slot->isNil();
    }

    /**
     * Return one base-slot dimension value from this timed slot.
     *
     * @param non-empty-string $name
     */
    public function dimension(string $name): ?string
    {
        return $this->slot->dimension($name);
    }

    /**
     * Compare two timed slots by their canonical serialized keys.
     */
    public function equals(self $other): bool
    {
        return $this->key === $other->key;
    }

    /**
     * Return the same base slot positioned at another point on the same time axis.
     */
    public function at(\DateTimeImmutable | int | string $time): self
    {
        return $this->space->slot($this->slot, $time);
    }

    /**
     * Return one human-readable `slot@time` serialization for display.
     */
    public function humanKey(): string
    {
        return $this->slot->key.'@'.$this->space->axis->humanKey($this->timeIndex);
    }

    /**
     * Return the canonical `slot@time` serialization for this timed slot.
     */
    public function __toString(): string
    {
        return $this->key;
    }
}
