<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class Inventory
{
    /** @var array<string, int> */
    private array $quantities = [];

    /**
     * @param array<array{SlotKey, int}> $tuples
     */
    public function __construct(array $tuples = [])
    {
        $this->set($tuples);
    }

    public function get(SlotKey $slot): int
    {
        return $this->quantities[(string) $slot] ?? 0;
    }

    /**
     * @param SlotKey|array<array{SlotKey, int}> $slots
     * @param mixed                              $quantity
     */
    public function set(SlotKey | array $slots, ?int $quantity = null): void
    {
        if (!is_array($slots)) {
            $slots = [[$slots, $quantity]];
        }
        /** @var array<array{SlotKey, int}> $slots */
        foreach ($slots as $tuple) {
            [$s, $q] = $tuple;
            $this->quantities[(string) $s] = $q;
        }
    }

    public function add(SlotKey $slot, int $delta): void
    {
        $key = (string) $slot;
        $this->quantities[$key] ??= 0;
        $this->quantities[$key] += $delta;
    }

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
     * @psalm-param \Closure(TRow): list<array{SlotKey|array<non-empty-string,string>, int}> $resolver
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
     * @psalm-param \Closure(TRow): list<array{SlotKey|array<non-empty-string,string>, int}> $resolver
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
