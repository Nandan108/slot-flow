<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class Slot
{
    /**
     * @param non-empty-string                           $key
     * @param ?array<non-empty-string, non-empty-string> $dimensions
     * @param array<string, mixed>                       $attributes
     */
    public function __construct(
        public readonly string $key,
        public readonly ?array $dimensions,
        public readonly SlotSpace $space,
        public readonly array $attributes = [],
    ) {
    }

    public function isNil(): bool
    {
        return null === $this->dimensions;
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

    public function equals(Slot $other): bool
    {
        return $this->key === $other->key;
    }

    /**
     * Return a copy of the slot with additional metadata attributes.
     *
     * Existing attributes keep precedence over newly provided ones.
     *
     * @param array<string, mixed> $attributes
     */
    public function withMeta(array $attributes): self
    {
        return new self($this->key, $this->dimensions, $this->space, $attributes + $this->attributes);
    }

    /**
     * @param array<non-empty-string, non-empty-string> $overrides
     *
     * @return array<Slot> an array containing the same slot, with values overriden by the override.
     *                     May return an empty array if the result after override is invalid (i.e. doesn't exist in the slot space).
     *                     If this is a nil slot, returns an array of all slots matching the overrides
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

    /** @return array<non-empty-string, MovementEdge> */
    public function outgoingEdges(): array
    {
        return $this->space->getEdgesFrom($this);
    }

    /** @return non-empty-string */
    public function __toString(): string
    {
        return $this->key;
    }
}
