<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * Union of partial slots.
 */
final class SlotPattern
{
    /**
     * Locale cache of matching slots for the pattern.
     *
     * @var array<non-empty-string, SlotKey>
     */
    private array $matchingSlots = [];

    /** @param list<array<non-empty-string, non-empty-string>|null> $partials */
    private function __construct(
        public array $partials,
        private SlotSpace $space,
    ) {
    }

    /** @param string|array<non-empty-string, ?string>|null $pattern */
    public static function from(string | array | null $pattern, SlotSpace $space): self
    {
        return new self($space->expandSlotPattern($pattern), $space);
    }

    /**
     * Get all slots in the SlotSpace that match the pattern.
     *
     * @return array<non-empty-string, SlotKey>
     **/
    public function expand(): array
    {
        if (!$this->matchingSlots) {
            foreach ($this->partials as $partial) {
                foreach ($this->space->matchPartial($partial) as $slot) {
                    $this->matchingSlots[$slot->key()] = $slot;
                }
            }
        }

        return $this->matchingSlots;
    }

    /**
     * Returns true if $slot matches the pattern, false otherwise.
     *
     * A slot matches the pattern if it matches at least one of the partial patterns.
     * A slot matches a partial pattern if all dimensions specified in the partial pattern match the corresponding dimensions in the slot.
     * A dimension matches if the value in the slot is equal to the value in the partial pattern.
     */
    public function matches(SlotKey $slot): bool
    {
        foreach ($this->partials as $partial) {
            if (null === $partial) {
                if ($slot->isNil()) {
                    return true;
                }
                continue;
            }
            foreach ($partial as $dim => $value) {
                if ($slot->dimension($dim) !== $value) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }
}
