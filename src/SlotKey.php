<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class SlotKey
{
    /**
     * Summary of __construct.
     *
     * @param non-empty-string                           $key
     * @param ?array<non-empty-string, non-empty-string> $dimensions
     */
    public function __construct(
        private string $key,
        private ?array $dimensions,
        private SlotSpace $space,
    ) {
    }

    /** @return non-empty-string */
    public function key(): string
    {
        return $this->key;
    }

    public function isNil(): bool
    {
        return null === $this->dimensions;
    }

    public function space(): SlotSpace
    {
        return $this->space;
    }

    /** @return ?array<non-empty-string, non-empty-string> */
    public function dimensions(): ?array
    {
        return $this->dimensions;
    }

    /**
     * Get the value of a specific dimension by name.
     *
     * @param non-empty-string $name
     *
     * @return non-empty-string
     */
    public function dimension(string $name): ?string
    {
        if (null === $this->dimensions) {
            return null;
        }

        return $this->dimensions[$name] ?? null;
    }

    public function equals(SlotKey $other): bool
    {
        return $this->key === $other->key;
    }

    /**
     * @param array<non-empty-string, non-empty-string> $overrides
     *
     * @return array<SlotKey> an array containing the same slot, with values overriden by the override.
     *                        May return an empty array if the result after override is invalid (i.e. doesn't exist in the slot space).
     *                        If this is a nil slot, returns an array of all slots matching the overrides
     */
    public function with(?array $overrides): array
    {
        if (null === $this->dimensions) {
            return $this->space->matchPartial($overrides);
        }

        $newDimensions = null === $overrides
            ? null // nil overrides into nil
            : $overrides + $this->dimensions;

        $slot = $this->space->trySlot($newDimensions);

        return $slot ? [$this->space->slot($newDimensions)] : [];
    }

    /** @return non-empty-string */
    public function __toString(): string
    {
        return $this->key;
    }
}
