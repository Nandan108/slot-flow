<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Batch;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Slot;
use Nandan108\SlotFlow\SlotSpace;

/**
 * Group of per-subject quantity states to be processed by the batch engine.
 *
 * @template TSubject
 *
 * @psalm-import-type TSlotPattern from SlotSpace
 * @psalm-import-type TSlotValues from SlotSpace
 *
 * @api
 */
class QuantityStateBatch
{
    /**
     * @param array<BatchItem> $items
     *
     * @psalm-param array<BatchItem<TSubject>> $items
     */
    public function __construct(
        private array $items,
    ) {
    }

    /**
     * Creates a QuantityStateBatch from an iterable of rows, using the provided closures to extract the necessary information.
     *
     * @psalm-template TRow
     * @psalm-template TFactorySubject
     *
     * @param \Closure      $subjectGetter   closure to get the subject from a row
     * @param \Closure      $slotRowGetter   closure to resolve slot dimensions and quantity from a row
     * @param \Closure      $quantityGetter  closure to get quantity from rows belonging to the same subject
     * @param \Closure|null $subjectIdGetter optional closure to get subject id from a row
     *
     * @psalm-param iterable<TRow>                                             $rows
     * @psalm-param \Closure(TRow): TFactorySubject                            $subjectGetter
     * @psalm-param \Closure(TRow): list<array{0: Slot|TSlotValues, 1: int, 2?: array<string, mixed>}> $slotRowGetter
     * @psalm-param \Closure(list<TRow>): int                                  $quantityGetter
     * @psalm-param ?\Closure(TFactorySubject): non-empty-string               $subjectIdGetter
     *
     * @psalm-return self<TFactorySubject>
     */
    public static function fromRows(
        SlotSpace $space,
        iterable $rows,
        \Closure $subjectGetter,
        \Closure $slotRowGetter,
        \Closure $quantityGetter,
        ?\Closure $subjectIdGetter,
    ): self {
        $subjectIdGetter ??= fn (mixed $subject): string => (string) $subject;

        /** @var array<string,array{subject:TFactorySubject,rows:list<TRow>}> $rowsBySubject */
        $rowsBySubject = [];
        foreach ($rows as $row) {
            $subject = $subjectGetter($row);
            $subjectId = $subjectIdGetter($subject);

            /** @psalm-suppress DocblockTypeContradiction */
            if (!is_string($subjectId) || '' === $subjectId) {
                throw new SlotFlowInvalidArgumentException(
                    'Subject ID must be a non-empty string.',
                    ['subject' => $subject, 'subject_id' => $subjectId],
                );
            }

            $rowsBySubject[$subjectId]['subject'] ??= $subject;
            $rowsBySubject[$subjectId]['rows'][] = $row;
        }

        /** @var list<BatchItem<TFactorySubject>> $batchItems */
        $batchItems = [];
        foreach ($rowsBySubject as ['subject' => $subject, 'rows' => $rows]) {
            $batchItems[] = new BatchItem(
                subject: $subject,
                quantity: $quantityGetter($rows),
                inventory: QuantityState::fromRows($space, $rows, $slotRowGetter),
            );
        }

        return new self($batchItems);
    }

    /**
     * Return the batch items in subject order.
     *
     * @return array<BatchItem>
     *
     * @psalm-return array<BatchItem<TSubject>>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Returns the movement results for each item in the batch.
     *
     * @return array<array{subject: mixed, result: MovementResult|null}>
     *
     * @psalm-return array<array{subject: TSubject, result: MovementResult|null}>
     */
    public function results(): array
    {
        return array_map(fn (BatchItem $item) => [
            'subject' => $item->subject,
            'result'  => $item->movementResult(),
        ], $this->items);
    }

    /**
     * Flatten all item results to batch-scoped quantity-state deltas.
     *
     * @return list<BatchQuantityStateDelta<TSubject>>
     */
    public function deltas(): array
    {
        $deltas = [];

        foreach ($this->items as $item) {
            $result = $item->movementResult();
            if (null === $result) {
                continue;
            }

            foreach ($result->deltas() as $delta) {
                $deltas[] = new BatchQuantityStateDelta(
                    subject: $item->subject,
                    slot: $delta->slot,
                    delta: $delta->delta,
                );
            }
        }

        return $deltas;
    }

    /**
     * Flatten all item results to batch-scoped ledger entries.
     *
     * @param array<string, mixed> $context
     *
     * @return list<BatchLedgerEntry<TSubject>>
     */
    public function ledgerEntries(array $context = []): array
    {
        $entries = [];

        foreach ($this->items as $item) {
            $result = $item->movementResult();
            if (null === $result) {
                continue;
            }

            foreach ($result->ledgerEntries($context) as $entry) {
                $entries[] = new BatchLedgerEntry(
                    subject: $item->subject,
                    edge: $entry->edge,
                    quantity: $entry->quantity,
                    initialFrom: $entry->initialFrom,
                    initialTo: $entry->initialTo,
                    context: $entry->context,
                );
            }
        }

        return $entries;
    }
}
