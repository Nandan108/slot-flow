<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;

/**
 * An instance of this class represents the inventory state of a single SKU,
 * as a mapping of slot keys to corresponding quantities.
 *
 * @psalm-import-type TSlotPattern from SlotSpace
 * @psalm-import-type TSlotValues from SlotSpace
 *
 * @psalm-type TQtty = int|float
 * @psalm-type TInventoryTuple = array{0: Slot|TSlotPattern, 1: TQtty, 2?: array<string, mixed>}
 *
 * @api
 */
final class Inventory
{
    /** @psalm-var array<string, TQtty> */
    private array $quantities = [];

    /** @var array<string, array<string, mixed>> */
    private array $slotAttributes = [];

    /**
     * @param array<array{Slot, int|float}> $tuples
     *
     * @psalm-param list<TInventoryTuple> $tuples
     */
    public function __construct(private SlotSpace $space, array $tuples = [])
    {
        $this->setTuple($tuples);
    }

    /**
     * @param Slot|array<string|null>|string|null $slot
     *
     * @psalm-param Slot|TSlotPattern $slot
     *
     * @psalm-return TQtty
     */
    public function get(Slot | array | string | null $slot): int | float
    {
        $slot = $slot instanceof Slot ? $slot : $this->space->slot($slot);

        /** @var TQtty */
        return $this->quantities[(string) $slot] ?? 0;
    }

    /**
     * Sum inventory quantities across one or more slots or slot patterns.
     *
     * Overlapping patterns are de-duplicated by slot key so each matching slot is
     * counted at most once in the returned total.
     *
     * @param Slot|array<string|null>|string|null ...$slotPatterns
     *
     * @psalm-param Slot|TSlotPattern ...$slotPatterns
     *
     * @psalm-return TQtty
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

        /** @var TQtty */
        $sum = 0;
        foreach ($slotsByKey as $slot) {
            /** @psalm-suppress InvalidOperand */
            $sum += $this->get($slot);
        }

        return $sum;
    }

    /**
     * Set the inventory state using a list of slot-quantity tuples, replacing any existing quantities.
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
     * @psalm-param TQtty $delta
     */
    public function add(Slot $slot, int | float $delta): void
    {
        $key = $slot->key;
        /** @var TQtty */
        $zero = 0;
        $this->quantities[$key] ??= $zero;

        // This method is intended to be used for either integers or floats,
        /** @psalm-suppress InvalidOperand, InvalidPropertyAssignmentValue */
        $this->quantities[$key] += $delta;
    }

    /**
     * @return array<string, int|float>
     *
     * @psalm-return array<string, TQtty>
     */
    public function all(): array
    {
        return $this->quantities;
    }

    /**
     * @return array<string, mixed>
     */
    public function slotAttributes(Slot $slot): array
    {
        return $this->slotAttributes[$slot->key] ?? [];
    }

    public function slotAttribute(Slot $slot, string $name, mixed $default = null): mixed
    {
        return $this->slotAttributes($slot)[$name] ?? $default;
    }

    public function copy(): self
    {
        $clone = new self($this->space);
        $clone->quantities = $this->quantities;
        $clone->slotAttributes = $this->slotAttributes;

        return $clone;
    }

    /**
     * @psalm-template TRow
     *
     * @param iterable $rows
     * @param \Closure $resolver closure to resolve slot dimensions and quantity from a row
     *
     * @psalm-param iterable<TRow> $rows
     * @psalm-param (\Closure(TRow): list<TInventoryTuple>|\Closure(TRow, SlotSpace): list<TInventoryTuple>) $resolver
     */
    public function addFromRows(array $rows, \Closure $resolver): self
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
     * @psalm-template TRow
     *
     * @param iterable $rows
     * @param \Closure $resolver closure to resolve slot dimensions and quantity from a row
     *
     * @psalm-param iterable<TRow> $rows
     * @psalm-param (\Closure(TRow): list<TInventoryTuple>|\Closure(TRow, SlotSpace): list<TInventoryTuple>) $resolver
     */
    public static function fromRows(
        SlotSpace $space,
        array $rows,
        \Closure $resolver,
    ): self {
        return (new self($space))->addFromRows($rows, $resolver);
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
            0       => $this->space->slot($slot), // delegate throw to SlotSpace::slot()
            1       => $matches[0],
            default => throw new SlotFlowInvalidArgumentException(
                'Inventory tuple slot pattern must resolve to exactly one slot.',
                ['slot_pattern' => $slot, 'matched_slots' => array_map(static fn (Slot $match): string => $match->key, $matches)],
            ),
        };
    }
}
