<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class SlotKey
{
    /**
     * Summary of __construct.
     *
     * @param non-empty-string                          $key
     * @param array<non-empty-string, non-empty-string> $dimensions
     */
    public function __construct(
        private string $key,
        private array $dimensions,
        private SlotSpace $space,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function space(): SlotSpace
    {
        return $this->space;
    }

    /** @return array<non-empty-string, non-empty-string> */
    public function dimensions(): array
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
    public function dimension(string $name): string
    {
        return $this->dimensions[$name];
    }

    public function equals(SlotKey $other): bool
    {
        return $this->key === $other->key;
    }

    /**
     * @param array<non-empty-string, non-empty-string> $overrides
     */
    public function with(array $overrides): self
    {
        $newDimensions = $overrides + $this->dimensions;

        return $this->space->slot($newDimensions);
    }

    public function __toString(): string
    {
        return $this->key;
    }
}
