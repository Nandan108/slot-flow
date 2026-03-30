<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Internal\SlotPattern;

/**
 * One concrete slot in a slot space, or the special `nil` boundary slot.
 *
 * @psalm-import-type TSlotPattern from SlotSpace
 *
 * @implements \ArrayAccess<int|string, non-empty-string>
 *
 * @api
 */
final class Slot implements \ArrayAccess
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

    /**
     * Return true when this is the special out-of-space nil slot.
     */
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

    /**
     * Compare two slots by their serialized key.
     */
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
     * Return slots produced by overriding dimensions on this slot.
     *
     * May return an empty array if the overridden slot does not exist. If this
     * is the nil slot, the overrides are treated as a partial pattern match.
     *
     * @param array<non-empty-string, non-empty-string> $overrides
     *
     * @return array<Slot>
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

    /**
     * @psalm-param TSlotPattern $pattern
     */
    public function matches(array | string | null $pattern): bool
    {
        return SlotPattern::from($pattern, $this->space)->matches($this);
    }

    /**
     * Return the currently valid outgoing edges from this slot.
     *
     * @return array<non-empty-string, MovementEdge>
     */
    public function outgoingEdges(): array
    {
        return $this->space->getEdgesFrom($this);
    }

    /**
     * Return the serialized slot key.
     *
     * @return non-empty-string
     */
    public function __toString(): string
    {
        return $this->key;
    }

    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return match (true) {
            null === $this->dimensions => false,
            is_string($offset)         => array_key_exists($offset, $this->dimensions),
            is_int($offset)            => $offset >= 0 && $offset < count($this->dimensions),
        };
    }

    #[\Override]
    public function offsetGet(mixed $offset): ?string
    {
        /** @psalm-suppress MixedArrayOffset */
        return match (true) {
            null === $this->dimensions => null,
            is_string($offset)         => $this->dimensions[$offset] ?? null,
            is_int($offset)            => array_values($this->dimensions)[$offset] ?? null,
        };
    }

    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Slot dimensions are immutable.');
    }

    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Slot dimensions are immutable.');
    }
}
