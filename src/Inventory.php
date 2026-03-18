<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * An instance of this class represents the inventory state of a single SKU,
 * as a mapping of slot keys to corresponding quantities.
 *
 * @template TQtty of int|float
 */
final class Inventory
{
    /** @var array<string, TQtty> */
    private array $quantities = [];

    /**
     * @param array<array{SlotKey, TQtty}> $tuples
     */
    public function __construct(array $tuples = [])
    {
        $this->set($tuples);
    }

    /** @return TQtty */
    public function get(SlotKey $slot): int | float
    {
        /** @var TQtty */
        return $this->quantities[(string) $slot] ?? 0;
    }

    /**
     * @param SlotKey|array<array{SlotKey, TQtty}> $slots
     * @param ?TQtty                               $quantity
     */
    public function set(SlotKey | array $slots, float | int | null $quantity = null): void
    {
        if (!is_array($slots)) {
            $slots = [[$slots, $quantity]];
        }
        /** @var array<array{SlotKey, TQtty}> $slots */
        foreach ($slots as $tuple) {
            [$s, $q] = $tuple;
            $this->quantities[(string) $s] = $q;
        }
    }

    /** @param TQtty $delta */
    public function add(SlotKey $slot, int | float $delta): void
    {
        $key = (string) $slot;
        /** @var TQtty */
        $zero = 0;
        $this->quantities[$key] ??= $zero;

        // This method is intended to be used for either integers or floats,
        /** @psalm-suppress InvalidOperand, InvalidPropertyAssignmentValue */
        $this->quantities[$key] += $delta;
    }

    /** @return array<string, TQtty> */
    public function all(): array
    {
        return $this->quantities;
    }

    public function copy(): self
    {
        $clone = new self();
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
     * @psalm-param \Closure(TRow): list<array{SlotKey|array<non-empty-string,string>, TQtty}> $resolver
     */
    public function addFromRows(
        SlotSpace $space,
        array $rows,
        \Closure $resolver,
    ): self {
        /** @var TRow $row */
        foreach ($rows as $row) {
            foreach ($resolver($row) as [$dimensions, $quantity]) {
                $this->add(
                    $dimensions instanceof SlotKey ? $dimensions : $space->slot($dimensions),
                    $quantity,
                );
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
     * @psalm-param iterable<TRow>                                             $rows
     * @psalm-param \Closure(TRow): list<array{SlotKey|array<non-empty-string,string>, TQtty}> $resolver
     */
    public static function fromRows(
        SlotSpace $space,
        array $rows,
        \Closure $resolver,
        ?Inventory $inventory = null,
    ): self {
        return (new self())->addFromRows($space, $rows, $resolver);
    }
}
