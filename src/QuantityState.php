<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;

/**
 * Quantity distribution state for one subject across the slot space.
 *
 * @psalm-import-type TSlotPattern from SlotSpace
 * @psalm-import-type TSlotValues from SlotSpace
 *
 * @psalm-consistent-constructor
 *
 * @psalm-type TInventoryTuple = array{0: Slot|TSlotPattern, 1: int|float, 2?: array<string, mixed>}
 *
 * @api
 */
class QuantityState
{
    /** @var array<non-empty-string, int|float> */
    private array $quantities = [];

    /** @var array<non-empty-string, array<string, mixed>> */
    private array $slotAttributes = [];

    /**
     * Create one quantity state from slot-quantity tuples.
     *
     * @param array<array{Slot, int|float}> $tuples
     *
     * @psalm-param list<TInventoryTuple> $tuples
     */
    public function __construct(private SlotSpace $space, array $tuples = [])
    {
        $this->setTuple($tuples);
    }

    /**
     * Get the quantity currently stored at one slot.
     *
     * @param Slot|array<string|null>|string|null $slot
     *
     * @psalm-param Slot|TSlotPattern $slot
     */
    public function get(Slot | array | string | null $slot): int | float
    {
        $slot = $slot instanceof Slot ? $slot : $this->space->slot($slot);

        return $this->quantities[(string) $slot] ?? 0;
    }

    /**
     * Sum quantities across one or more slots or slot patterns.
     *
     * Overlapping patterns are de-duplicated by slot key so each matching slot is
     * counted at most once in the returned total.
     *
     * @param Slot|array<string|null>|string|null ...$slotPatterns
     *
     * @psalm-param Slot|TSlotPattern ...$slotPatterns
     */
    public function getSum(Slot | array | string | null ...$slotPatterns): int | float
    {
        /** @var array<string, Slot> $slotsByKey */
        $slotsByKey = [];

        foreach ($slotPatterns as $slotPattern) {
            if ($slotPattern instanceof Slot) {
                $slotsByKey[$slotPattern->key] = $slotPattern;
                continue;
            }

            foreach ($this->space->matchPattern($slotPattern) as $slot) {
                $slotsByKey[$slot->key] = $slot;
            }
        }

        $sum = 0;
        foreach ($slotsByKey as $slot) {
            /** @psalm-suppress InvalidOperand */
            $sum += $this->get($slot);
        }

        return $sum;
    }

    /**
     * Set the quantity state using a list of slot-quantity tuples, replacing any existing quantities.
     *
     * @param array<array{Slot, int|float}> $slots
     *
     * @psalm-param list<TInventoryTuple> $slots
     */
    public function setTuple(array $slots): void
    {
        foreach ($slots as $tuple) {
            [$slot, $quantity, $attributes] = $tuple + [null, null, null];
            $resolvedSlot = $this->resolveSingleSlot($slot);
            $key = $resolvedSlot->key;
            $this->quantities[$key] = $quantity;
            $this->rememberSlotAttributes($resolvedSlot, $attributes);
        }
    }

    /**
     * Set the quantity for a given slot, replacing any existing quantity.
     */
    public function setSlotQtty(Slot $slot, int | float $quantity): void
    {
        $this->quantities[$slot->key] = $quantity;
    }

    /**
     * Add a signed quantity delta to one slot.
     */
    public function add(Slot $slot, int | float $delta): void
    {
        $key = $slot->key;
        $this->quantities[$key] ??= 0;

        /** @psalm-suppress InvalidOperand, InvalidPropertyAssignmentValue */
        $this->quantities[$key] += $delta;
    }

    /**
     * Return all non-zero stored quantities keyed by slot key.
     *
     * @return array<non-empty-string, int|float>
     */
    public function all(): array
    {
        return $this->quantities;
    }

    /**
     * Return per-slot attributes remembered during ingestion or tuple loading.
     *
     * @return array<string, mixed>
     */
    public function slotAttributes(Slot $slot): array
    {
        return $this->slotAttributes[$slot->key] ?? [];
    }

    /**
     * Return remembered attributes for all known slots, keyed by slot key.
     *
     * @return array<non-empty-string, array<string, mixed>>
     *
     * @psalm-return array<non-empty-string, array<string, mixed>>
     */
    public function allSlotAttributes(): array
    {
        return $this->slotAttributes;
    }

    /**
     * Read one remembered slot attribute with an optional default.
     */
    public function slotAttribute(Slot $slot, string $name, mixed $default = null): mixed
    {
        return $this->slotAttributes($slot)[$name] ?? $default;
    }

    /**
     * Clone the quantity state and remembered slot attributes.
     */
    public function copy(): static
    {
        $clone = new static($this->space);
        $clone->quantities = $this->quantities;
        $clone->slotAttributes = $this->slotAttributes;

        return $clone;
    }

    /**
     * Return the slot space this quantity state belongs to.
     */
    public function space(): SlotSpace
    {
        return $this->space;
    }

    /**
     * Add quantities from rows using a resolver that yields slot tuples.
     *
     * @psalm-template TRow
     *
     * @param iterable $rows
     *
     * @psalm-param iterable<TRow> $rows
     * @psalm-param (\Closure(TRow): list<TInventoryTuple>|\Closure(TRow, SlotSpace): list<TInventoryTuple>) $resolver
     */
    public function addFromRows(array $rows, \Closure $resolver): static
    {
        /** @var TRow $row */
        foreach ($rows as $row) {
            foreach ($resolver($row, $this->space) as $tuple) {
                [$slot, $quantity, $attributes] = $tuple + [null, null, null];
                $slot = $this->resolveSingleSlot($slot);
                $this->rememberSlotAttributes($slot, $attributes);
                $this->add($slot, $quantity);
            }
        }

        return $this;
    }

    /**
     * Build a new quantity state from rows using a tuple resolver.
     *
     * @psalm-template TRow
     *
     * @param iterable $rows
     *
     * @psalm-param iterable<TRow> $rows
     * @psalm-param (\Closure(TRow): list<TInventoryTuple>|\Closure(TRow, SlotSpace): list<TInventoryTuple>) $resolver
     */
    public static function fromRows(
        SlotSpace $space,
        array $rows,
        \Closure $resolver,
    ): static {
        return (new static($space))->addFromRows($rows, $resolver);
    }

    /**
     * @param array<string, mixed>|null $attributes
     */
    private function rememberSlotAttributes(Slot $slot, ?array $attributes = null): void
    {
        $merged = ($attributes ?? []) + $slot->attributes;
        if ([] === $merged) {
            return;
        }

        $key = $slot->key;
        $this->slotAttributes[$key] = ($this->slotAttributes[$key] ?? []) + $merged;
    }

    /**
     * Resolve a tuple slot input to exactly one slot.
     *
     * @psalm-param Slot|TSlotPattern $slot
     */
    private function resolveSingleSlot(Slot | array | string | null $slot): Slot
    {
        if ($slot instanceof Slot) {
            return $slot;
        }

        $matches = $this->space->matchPattern($slot);

        return match (count($matches)) {
            0       => $this->space->slot($slot),
            1       => $matches[0],
            default => throw new SlotFlowInvalidArgumentException(
                'Inventory tuple slot pattern must resolve to exactly one slot.',
                ['slot_pattern' => $slot, 'matched_slots' => array_map(static fn (Slot $match): string => $match->key, $matches)],
            ),
        };
    }
}
