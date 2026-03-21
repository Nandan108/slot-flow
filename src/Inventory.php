<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * An instance of this class represents the inventory state of a single SKU,
 * as a mapping of slot keys to corresponding quantities.
 *
 * @psalm-import-type TSlotValues from SlotSpace
 *
 * @psalm-type TQtty = int|float
 * @psalm-type TInventoryTuple = array{Slot|TSlotValues, TQtty}
 */
final class Inventory
{
    /** @psalm-var array<string, TQtty> */
    private array $quantities = [];

    /**
     * @param array<array{Slot, int|float}> $tuples
     *
     * @psalm-param list<TInventoryTuple> $tuples
     */
    public function __construct(private SlotSpace $space, array $tuples = [])
    {
        foreach ($tuples as $tuple) {
            [$slot, $quantity] = $tuple;
            if ($slot instanceof Slot) {
                $key = $slot->key();
            } else {
                // @psalm-suppress InvalidArgument because we allow both Slot and TSlotValues in the tuple
                $key = $space->slot($slot)->key();
            }
            $this->quantities[$key] = $quantity;
        }
        $this->setTuple($tuples);
    }

    /**
     * @psalm-return TQtty
     */
    public function get(Slot $slot): int | float
    {
        /** @var TQtty */
        return $this->quantities[(string) $slot] ?? 0;
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
            [$slot, $quantity] = $tuple;
            $key = ($slot instanceof Slot ? $slot : $this->space->slot($slot))->key();
            $this->quantities[$key] = $quantity;
        }
    }

    /**
     * Set the quantity for a given slot, replacing any existing quantity.
     */
    public function setSlotQtty(Slot $slot, int | float $quantity): void
    {
        $this->quantities[$slot->key()] = $quantity;
    }

    /**
     * @psalm-param TQtty $delta
     */
    public function add(Slot $slot, int | float $delta): void
    {
        $key = $slot->key();
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

    public function copy(): self
    {
        $clone = new self($this->space);
        $clone->quantities = $this->quantities;

        return $clone;
    }

    /**
     * @psalm-template TRow
     *
     * @param iterable $rows
     * @param \Closure $resolver closure to resolve slot dimensions and quantity from a row
     *
     * @psalm-param iterable<TRow>                                             $rows
     * @psalm-param \Closure(TRow, SlotSpace): list<TInventoryTuple> $resolver
     */
    public function addFromRows(array $rows, \Closure $resolver): self
    {
        /** @var TRow $row */
        foreach ($rows as $row) {
            foreach ($resolver($row, $this->space) as [$slot, $quantity]) {
                $slot = ($slot instanceof Slot ? $slot : $this->space->slot($slot));
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
     * @psalm-param \Closure(TRow, SlotSpace): list<TInventoryTuple> $resolver
     */
    public static function fromRows(
        SlotSpace $space,
        array $rows,
        \Closure $resolver,
    ): self {
        return (new self($space))->addFromRows($rows, $resolver);
    }
}
