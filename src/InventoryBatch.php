<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * @template TVariant
 */
final class InventoryBatch
{
    /** @param array<BatchItem<TVariant>> $items */
    public function __construct(
        private array $items,
    ) {
    }

    /**
     * Creates an InventoryBatch from an iterable of rows, using the provided closures to extract the necessary information.
     *
     * @psalm-template TRow
     * @psalm-template TFactoryVariant
     *
     * @param \Closure      $variantGetter   closure to get the variant from a row
     * @param \Closure      $slotRowGetter   closure to resolve slot dimensions and quantity from a row
     * @param \Closure      $quantityGetter  closure to get quantity from rows belonging to the same variant
     * @param \Closure|null $variantIdGetter optional closure to get variant id from a row
     *
     * @psalm-param iterable<TRow>                                             $rows
     * @psalm-param \Closure(TRow): TFactoryVariant                            $variantGetter
     * @psalm-param \Closure(TRow): list<array{SlotKey|array<non-empty-string,string>, int}> $slotRowGetter
     * @psalm-param \Closure(list<TRow>): int                                  $quantityGetter
     * @psalm-param ?\Closure(TFactoryVariant): non-empty-string               $variantIdGetter
     *
     * @psalm-return self<TFactoryVariant>
     */
    public static function fromRows(
        SlotSpace $space,
        iterable $rows,
        \Closure $variantGetter,
        \Closure $slotRowGetter,
        \Closure $quantityGetter,
        ?\Closure $variantIdGetter,
    ): self {
        // If no variant ID getter is provided, we will use the string representation of the variant as the ID
        $variantIdGetter ??= fn (mixed $variant): string => (string) $variant;

        // In some systems, dimension data for a single variant might be spread across multiple rows, while in others
        // a single row may contain data for multile slots of the same variant. To accommodate both cases, we first gather
        // rows by variant using the provided closure to get the variant id, the variant field is mixed but we expect the
        // closure to return a non-empty-string that can be used as an array key, otherwise we will throw an exception.
        /** @var array<string,array{variant:TFactoryVariant,rows:list<TRow>}> $rowsByVariant */
        $rowsByVariant = [];
        foreach ($rows as $row) {
            $variant = $variantGetter($row);
            $variantId = $variantIdGetter($variant);

            /** @psalm-suppress DocblockTypeContradiction */
            if (!is_string($variantId) || '' === $variantId) {
                throw new \InvalidArgumentException('Variant ID must be a non-empty string.');
            }

            $rowsByVariant[$variantId]['variant'] ??= $variant;
            $rowsByVariant[$variantId]['rows'][] = $row;
        }

        /** @var list<BatchItem<TFactoryVariant>> $batchItems */
        $batchItems = [];
        foreach ($rowsByVariant as ['variant' => $variant, 'rows' => $rows]) {
            $batchItems[] = new BatchItem(
                variant: $variant,
                quantity: $quantityGetter($rows),
                inventory: Inventory::fromRows($space, $rows, $slotRowGetter),
            );
        }

        return new self($batchItems);
    }

    /** @return array<BatchItem<TVariant>> */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Returns the movement results for each item in the batch.
     *
     * @return array<array{variant: TVariant, result: MovementResult|null}>
     */
    public function results(): array
    {
        return array_map(fn (BatchItem $item) => [
            'variant' => $item->variant(),
            'result'  => $item->movementResult(),
        ], $this->items);
    }
}
