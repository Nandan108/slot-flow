<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Nandan108\SlotFlow\Batch\BatchMovementEngine;
use Nandan108\SlotFlow\Batch\QuantityStateBatch;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\Policies\DimensionPriority;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\Rules\RuleSet;
use Nandan108\SlotFlow\Rules\SlotRule;
use Nandan108\SlotFlow\Slot;
use Nandan108\SlotFlow\SlotSpace;

/**
 * @psalm-type VariantType non-empty-string
 * @psalm-type TRow = array{
 *   var: VariantType,
 *   mvQtty: int,
 *   loc: non-empty-string,
 *   own: non-empty-string,
 *   ifs?: int,
 *   inv: array{
 *     inb?: int,
 *     fs?: int,
 *     res?: int,
 *     sd?: int,
 *     dsp?: int,
 *     dlv?: int,
 *     ret?: int,
 *     def?: int
 *   }
 * }
 *
 * @psalm-import-type TEdgePattern from \Nandan108\SlotFlow\SlotSpace
 */
final class CommerceFlowExample
{
    private SlotSpace $space;

    private const STATES = [
        'fs',  // forsale: available stock that can be sold
        'res', // reserved: stock that is reserved in customers' carts but not yet sold
        'sd',  // sold: stock that has been sold but not yet dispatched
        'ret', // returned: stock that has been returned by customers, not yet inspected
        'def', // defect: stock that is defective and cannot be sold
    ];

    private const OWNERSHIP_TYPES = [
        'CS', // Consignment stock owned by Supplier
        'CP', // Consignment stock Purchased (now owned by us, but we remember it was consignment)
        'FP', // Firm Purchase stock
    ];

    private const LOCATIONS = [
        'sup', // supplier
        'eu',  // European partner warehouse (serves EU customers)
        'wh1', // Swiss warehouse 1
        'wh2', // Swiss warehouse 2
        'cst', // customer
    ];

    public function __construct()
    {
        $this->space = SlotSpace::define(
            [
                'loc' => self::LOCATIONS,
                'own' => self::OWNERSHIP_TYPES,
                'stt' => self::STATES,
            ],
        )->slotRules(RuleSet::from(
            SlotRule::allow('*'), // allow all movements by default
            SlotRule::allow('wh2.*.*')->meta(['food-storage' => true]), // metadata for policy rules
        ))->edgeRules([
            // deny ownership change between consignment and firm purchase
            EdgeRule::disconnect(['own' => 'C*'], ['own' => 'FP']),
            // allow returned consignment stock can be regularized as firm purchase stock
            EdgeRule::allow(['own' => 'C*', 'stt' => 'ret'], ['own' => 'CP', 'stt' => 'fs']),
            // deny movement directly between supplier and customer
            EdgeRule::disconnect(['loc' => 'sup'], ['loc' => 'cst']),
        ])

        // Define cascades for common commerce flows. These are just examples - in a real system, the
        // actual cascades and their steps would be defined based on the specific business rules and
        // processes of the company.
        // The movement engine can also support ad-hoc cascades defined at runtime, so not every
        // possible flow needs to be predefined here.
            // PO reception: move from supplier to warehouse, then optionally regularize to forsale
            // if received quantity is higher than ordered quantity
            ->flow('receive-po', static fn (Flow $c) => $c
                ->move('sup.{own}.{from-state}', ['loc' => '{loc}'])
                // note the use of ->create() to allow creation of new quantities
                // from 'nil', the source/sink slot that represents outside of the system.
                ->create('{loc}.{own}.fs'))

            // Reservation: allow reservation from any forsale stock, this is done right before
            // engaging payment gateway, when purchase intent is clear and confirmed.
            ->flow(
                'reserve',
                static fn (Flow $c) => $c
                 ->move(['stt' => 'fs'], ['stt' => 'res']) // reserved stock can be sold
                 ->orderBy(new DimensionPriority([
                     'loc' => ['wh*', 'sup'], // prefer to sell from warehouses before suppliers
                     'own' => ['FP', 'CP', 'CS'], // prefer to sell firm purchase and owned stock before consignment stock
                 ])),
            )
            // reserved stock can be released back to forsale after a timeout if payment doesn't go through
            ->flow('release', [[['stt' => 'res'], ['stt' => 'fs']]])

            // Booking (checkout): move customer's reserved stock to "sold" status
            ->flow('book', [[['stt' => 'res'], ['stt' => 'sd']]]);
        $this->space
            // Order cancellation: move from sold back to forsale
            ->flow('cancelBooking', static fn (Flow $c) => $c
                 ->move(['stt' => 'fs'], ['stt' => 'fs']) // reserved stock can be sold
                 ->orderBy(new DimensionPriority([
                     'loc' => ['sup', 'wh*'], // prefer to sell from warehouses before suppliers
                     'own' => ['CS', 'CP', 'FP'], // prefer to sell firm purchase and owned stock before consignment stock
                 ])))

            // --- The following post-booking flows won't be implemented in this example
            // // dispatch can happen from any sold stock
            // ->cascade('dispatch', [['*.*.sd', '*.*.dsp']])
            // // delivery can happen from any dispatched stock  (requires parcel tracking api integration to confirm delivery)
            // ->cascade('deliver', [['*.*.dsp', '*.*.dlv']])
            // // return can be initiated from any delivered stock
            // ->cascade('return-dispatch', [['*.*.dlv', '*.*.rdp']])
            // // completion can happen from any delivered stock (no return initiated + return window has closed)
            // ->cascade('complete', [['*.*.dlv', null]])
            // // return can happen from delivered stock (requires parcel tracking api integration)
            // ->cascade('return', [['*.*.dlv', '*.*.ret']])
            // // returned stock can be regularized to forsale
            // ->cascade('regularize', [['*.*.ret', '*.*.fs']])
            // // any inbound, forsale, or returned stock can be marked as defective
            // ->cascade('defect', [['*.*.inb|fs|ret', '*.*.def']])

            // defective stock can be discarded
            // here we use ->destroy(), which moves quantities to the 'nil' slot, representing outside of the system.
            // Think of it as displacing quantities to /dev/null
            ->flow('discard', static fn (Flow $c) => $c->destroy('*.*.def'));
    }

    /**
     * Takes database rows.
     *
     * @param array<TRow> $rows
     *
     * @return QuantityStateBatch<VariantType>
     */
    public function prepareBatch(array $rows): QuantityStateBatch
    {
        $space = $this->space;
        /** @var \Closure(TRow): list<array{0: Slot|array<non-empty-string, non-empty-string>, 1: int, 2?: array<string, mixed>}> $slotRowGetter */
        $slotRowGetter = static function (array $row) use ($space): array {
            /** @var TRow $row */
            return self::slotRowsForSpace($space, $row);
        };

        return QuantityStateBatch::fromRows(
            space: $space,
            rows: $rows,
            /** @param TRow $row */
            subjectGetter: fn ($row): string => $row['var'],
            slotRowGetter: $slotRowGetter,
            /** @param list<TRow> $rows */
            quantityGetter: fn (array $rows) => $rows[array_key_first($rows) ?? 0]['mvQtty'],
            /** @param VariantType $variant */
            subjectIdGetter: fn (string $variant): string => $variant,
        );
    }

    /**
     * @param TRow $row
     *
     * @return list<array{0: Slot, 1: int, 2?: array<string, mixed>}>
     */
    private static function slotRowsForSpace(SlotSpace $space, array $row): array
    {
        $slotRows = [];
        $ifs = $row['ifs'] ?? 0;
        foreach (self::STATES as $state) {
            if ($quantity = ($row['inv'][$state] ?? 0)) {
                $slotRows[] = [
                    $space->slot(['loc' => $row['loc'], 'own' => $row['own'], 'stt' => $state])
                        ->withMeta(['ifs' => $ifs]),
                    $quantity,
                ];
            }
        }

        return $slotRows;
    }

    /**
     * Takes database rows.
     *
     * @param list<TRow>                 $rows
     * @param array<mixed>               $context
     * @param array<string, scalar|null> $params
     *
     * @psalm-return array{result: MovementResult|null, subject: non-empty-string}[] $results
     */
    public function processBatch(array $rows, string | Flow $flow, array $context = [], array $params = []): array
    {
        $engine = new BatchMovementEngine(new MovementEngine());

        return $engine
            ->execute(
                batch: $this->prepareBatch($rows),
                space: $this->space,
                cascade: $flow,
                context: $context,
                params: $params,
            )
            ->results();
    }

    /**
     * @param list<TRow>       $optsAndQuantities rows (one per variant) with fields:
     *                                            `var`= variant object
     *                                            `mvQtty`= quantity to move,
     *                                            `loc`= location code,
     *                                            `own`= ownership code ('CS'|'CP'|'FP')
     *                                            `fs`/`sd`= inventory state 'for-sale'/'sold'
     * @param 'CS'|'CP'|'FP'   $ownership         'CS' for Consignment, Supplier owned (not yet purchased),
     *                                            'CP' for Consignment, now Purchased
     *                                            'FP' for Firm Purchase
     * @param non-empty-string $locCode           A warehouse code, as defined in 'loc' dimension. E.g. 'wh1', 'wh2'
     * @param bool             $reverse           whether to reverse the movement path
     *                                            (e.g. for adjusting purchase order reception errors)
     *
     * @psalm-return array{result: MovementResult|null, subject: non-empty-string}[] $results
     **/
    public function receivePO(array $optsAndQuantities, string $ownership, string $locCode, bool $reverse): array
    {
        return $this->processBatch(
            rows: $optsAndQuantities,
            flow: $this->space->getFlow('receive-po')->reverseIf($reverse),
            params: [
                'loc'   => $locCode,
                'own'   => $ownership,
                // consignment stock: we move only what was sold (sup.CS.sd => wh*.CS.sd)
                // owned stock: we move regardless of state
                'from-state'   => 'CS' === $ownership ? 'sd' : '*',
            ],
        );
    }

    /**
     * Reserve saleable stock into cart reservations.
     *
     * @param list<TRow> $rows
     *
     * @psalm-return array{result: MovementResult|null, subject: non-empty-string}[] $results
     */
    public function reserve(array $rows): array
    {
        return $this->processBatch($rows, 'reserve');
    }

    /**
     * Release reserved cart stock back to saleable stock.
     *
     * @param list<TRow> $rows
     *
     * @psalm-return array{result: MovementResult|null, subject: non-empty-string}[] $results
     */
    public function release(array $rows): array
    {
        return $this->processBatch($rows, 'release');
    }

    /**
     * Confirm reserved stock as sold.
     *
     * @param list<TRow> $rows
     *
     * @psalm-return array{result: MovementResult|null, subject: non-empty-string}[] $results
     */
    public function book(array $rows): array
    {
        return $this->processBatch($rows, 'book');
    }

    /**
     * Discard defective stock.
     *
     * @param list<TRow> $rows
     *
     * @psalm-return array{result: MovementResult|null, subject: non-empty-string}[] $results
     */
    public function discardDefective(array $rows): array
    {
        return $this->processBatch($rows, 'discard');
    }
}

final class FooVariant
{
    /** @param non-empty-string $id */
    public function __construct(
        public string $id,
    ) {
    }
}
