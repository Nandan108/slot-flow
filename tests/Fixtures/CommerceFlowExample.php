<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Nandan108\SlotFlow\BatchMovementEngine;
use Nandan108\SlotFlow\Cascade;
use Nandan108\SlotFlow\DimensionPriority;
use Nandan108\SlotFlow\EdgeRule;
use Nandan108\SlotFlow\Inventory;
use Nandan108\SlotFlow\InventoryBatch;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\RuleSet;
use Nandan108\SlotFlow\Slot;
use Nandan108\SlotFlow\SlotRule;
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
        'inb',  // inbound from supplier: stock is expected but not yet received
        'fs',   // forsale: available stock that can be sold
        'res',  // reserved: stock that is reserved in customers' carts but not yet sold
        'sd',   // sold: stock that has been sold but not yet dispatched
        'dsp',  // dispatched: stock that has been sold and dispatched but not yet delivered
        'rdp',  // return-dispatch: stock that has been sold and for which a return has been initiated but not yet received back
        'dlv',  // delivered: stock that has been sold and delivered but not yet returned or marked as completed
        'ret',  // returned: stock that has been returned by customers but not yet processed
        'def',  // defect: stock that is defective and cannot be sold
    ];

    private const OWNERSHIP_TYPES = [
        // consignment stock: stock that is owned by the supplier but stored in our warehouse. We only pay for it
        // when it's sold, but we also have less control over it (e.g. sending back to supplier may be costly or impossible)
        'C',
        // firm purchase stock: stock that is owned by us. We have more control over it,
        // but we also bear the cost of holding it until it's sold
        'F',
    ];

    private const LOCATIONS = [
        'sup',  // supplier
        'eu',   // European partner warehouse (serves EU customers)
        'wh1',  // Swiss warehouse 1
        'wh2',  // Swiss warehouse 2
    ];

    public function __construct()
    {
        $this->space = SlotSpace::define(
            [
                'loc'   => self::LOCATIONS,
                'own'   => self::OWNERSHIP_TYPES,
                'state' => self::STATES,
            ],
        )->applySlotRules(RuleSet::from(
            SlotRule::allow('*'), // allow all movements by default
            SlotRule::allow('wh2.*.*')->meta(['food-storage' => true]), // metadata for policy rules
            SlotRule::deny('eu.*.inb'), // Stock is never received from EU warehouse, so we can deny any movement from EU_inb to prevent mistakes
        ))->applyEdgeRules([
            EdgeRule::disconnect('*.C.*', '*.F.*'), // deny ownership change between consignment and firm purchase in both directions
            EdgeRule::allow('wh*.C.ret', 'wh*.F.fs'), // allow returned consignment stock can be regularized as firm purchase stock
        ]);

        // Define cascades for common commerce flows. These are just examples - in a real system, the
        // actual cascades and their steps would be defined based on the specific business rules and
        // processes of the company.
        // The movement engine can also support ad-hoc cascades defined at runtime, so not every
        // possible flow needs to be predefined here.
        $this->space
            // PO reception: move from supplier to warehouse, then optionally regularize to forsale
            // if received quantity is higher than ordered quantity
            ->cascade('receive-po', static fn (Cascade $c) => $c
                ->move('sup.{own}.{state}', '{loc}.*.*')
                ->create('{loc}.{own}.fs'))
            // Reservation (add to card): allow reservation from any forsale stock
            ->cascade('reserve', static fn (Cascade $c) => $c
                 ->move('*.*.fs', '*.*.res') // reserved stock can be sold
                 ->orderBy(new DimensionPriority([
                     'loc' => ['wh*', 'sup'], // prefer to sell from warehouses before suppliers
                     'own' => ['F', 'C'], // prefer to sell firm purchase stock before consignment stock
                 ])))
            // Booking (checkout): allow booking from any stock for-sale and reserved (by current customer)
            // This would require that the inventory's reserved qtty only show the quantity reserved by the
            // current customer, which would be an implementation detail of the Inventory class
            ->cascade('book', static fn (Cascade $c) => $c
                 ->move('*.*.res|fs', '*.*.sd') // reserved stock can be sold
                 ->orderBy(new DimensionPriority([
                     'loc' => ['wh*', 'sup'], // prefer to sell from warehouses before suppliers
                     'own' => ['F', 'C'], // prefer to sell firm purchase stock before consignment stock
                 ])))

            // --- The following cascades have not been refined ---
            // Cancellation can move from sold back to forsale - this needs to be refined to
            // handle the difference between consignment stock (which can only be moved back to forsale if the sale is still ongoing, i.e. with limitFsByIfs) and firm purchase stock (which can be moved back to forsale anytime)
            ->cascade('cancelBooking', [['*.*.sd', '*.*.fs']])
            // reserved stock can be released back to forsale on cart expiry
            ->cascade('release', [['*.*.res', '*.*.fs']])
            // dispatch can happen from any sold stock
            ->cascade('dispatch', [['*.*.sd', '*.*.dsp']])
            // delivery can happen from any dispatched stock  (requires parcel tracking api integration to confirm delivery)
            ->cascade('deliver', [['*.*.dsp', '*.*.dlv']])
            // return can be initiated from any delivered stock
            ->cascade('return-dispatch', [['*.*.dlv', '*.*.rdp']])
            // completion can happen from any delivered stock (no return initiated + return window has closed)
            ->cascade('complete', [['*.*.dlv', null]])
            // return can happen from delivered stock (requires parcel tracking api integration)
            ->cascade('return', [['*.*.dlv', '*.*.ret']])
            // returned stock can be regularized to forsale
            ->cascade('regularize', [['*.*.ret', '*.*.fs']])
            // any inbound, forsale, or returned stock can be marked as defective
            ->cascade('defect', [['*.*.inb|fs|ret', '*.*.def']])
            // defective stock can be discarded
            ->cascade('discard', static fn (Cascade $c) => $c->destroy('*.*.def'));
    }

    /**
     * Takes database rows.
     *
     * @param array<TRow> $rows
     *
     * @return InventoryBatch<VariantType>
     */
    public function prepareBatch(array $rows): InventoryBatch
    {
        $space = $this->space;
        /** @var \Closure(TRow): list<array{Slot|array<non-empty-string, non-empty-string>, int}> $slotRowGetter */
        $slotRowGetter = static function (array $row) use ($space): array {
            /** @var TRow $row */
            return self::slotRowsForSpace($space, $row);
        };

        return InventoryBatch::fromRows(
            space: $space,
            rows: $rows,
            /** @param TRow $row */
            variantGetter: fn ($row): string => $row['var'],
            slotRowGetter: $slotRowGetter,
            /** @param list<TRow> $rows */
            quantityGetter: fn (array $rows) => $rows[array_key_first($rows) ?? 0]['mvQtty'],
            /** @param VariantType $variant */
            variantIdGetter: fn (string $variant): string => $variant,
        );
    }

    /**
     * @param TRow $row
     *
     * @return list<array{0: Slot, 1: int}>
     */
    private static function slotRowsForSpace(SlotSpace $space, array $row): array
    {
        $slotRows = [];
        $ifs = $row['ifs'] ?? 0;
        foreach (self::STATES as $state) {
            if ($quantity = ($row['inv'][$state] ?? 0)) {
                $slotRows[] = [
                    $space->slot(['loc' => $row['loc'], 'own' => $row['own'], 'state' => $state])
                        ->meta(['ifs' => $ifs]),
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
     * @psalm-return array{result: MovementResult|null, variant: non-empty-string}[] $results
     */
    public function processBatch(array $rows, Cascade $cascade, array $context = [], array $params = []): array
    {
        $engine = new BatchMovementEngine(new MovementEngine());

        return $engine
            ->execute(
                batch: $this->prepareBatch($rows),
                space: $this->space,
                cascade: $cascade,
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
     *                                            `own`= ownership code ('C' for consignment or 'F' for firm purchase)
     *                                            `fs`/`sd`= inventory state 'for-sale'/'sold'
     * @param 'C'|'F'          $ownership         'C' for consignment or 'F' for firm purchase
     * @param non-empty-string $locCode           A warehouse code, as defined in 'loc' dimension. E.g. 'wh1', 'wh2'
     * @param bool             $reverse           whether to reverse the movement path
     *                                            (e.g. for adjusting purchase order reception errors)
     *
     * @psalm-return array{result: MovementResult|null, variant: non-empty-string}[] $results
     **/
    public function moveReceivePO(array $optsAndQuantities, string $ownership, string $locCode, bool $reverse): array
    {
        return $this->processBatch(
            rows: $optsAndQuantities,
            cascade: $this->space->getCascade('receive-po')->reverseIf($reverse),
            params: [
                'loc'   => $locCode,
                'own'   => $ownership,
                'state' => match ($ownership) {
                    // consignment stock: we move only what was sold (sup.C.sd => wh*.C.sd)
                    'C' => 'sd',
                    // firm purchase stock: we move regardless of state
                    'F' => '*',
                },
            ],
        );
    }

    /**
     * Reserve saleable stock into cart reservations.
     *
     * @param list<TRow> $rows
     *
     * @psalm-return array{result: MovementResult|null, variant: non-empty-string}[] $results
     */
    public function reserve(array $rows): array
    {
        return $this->processNamedCascade($rows, 'reserve');
    }

    /**
     * Release reserved cart stock back to saleable stock.
     *
     * @param list<TRow> $rows
     *
     * @psalm-return array{result: MovementResult|null, variant: non-empty-string}[] $results
     */
    public function release(array $rows): array
    {
        return $this->processNamedCascade($rows, 'release');
    }

    /**
     * Confirm reserved stock as sold.
     *
     * @param list<TRow> $rows
     *
     * @psalm-return array{result: MovementResult|null, variant: non-empty-string}[] $results
     */
    public function book(array $rows): array
    {
        return $this->processNamedCascade($rows, 'book');
    }

    /**
     * Dispatch sold stock.
     *
     * @param list<TRow> $rows
     *
     * @psalm-return array{result: MovementResult|null, variant: non-empty-string}[] $results
     */
    public function dispatch(array $rows): array
    {
        return $this->processNamedCascade($rows, 'dispatch');
    }

    /**
     * Confirm delivery of dispatched stock.
     *
     * @param list<TRow> $rows
     *
     * @psalm-return array{result: MovementResult|null, variant: non-empty-string}[] $results
     */
    public function deliver(array $rows): array
    {
        return $this->processNamedCascade($rows, 'deliver');
    }

    /**
     * Register a return from delivered stock.
     *
     * @param list<TRow> $rows
     *
     * @psalm-return array{result: MovementResult|null, variant: non-empty-string}[] $results
     */
    public function acceptReturn(array $rows): array
    {
        return $this->processNamedCascade($rows, 'return');
    }

    /**
     * Regularize returned stock back into saleable stock.
     *
     * @param list<TRow> $rows
     *
     * @psalm-return array{result: MovementResult|null, variant: non-empty-string}[] $results
     */
    public function restockReturn(array $rows): array
    {
        return $this->processNamedCascade($rows, 'regularize');
    }

    /**
     * Mark stock as defective from inbound, saleable, or returned stock.
     *
     * @param list<TRow> $rows
     *
     * @psalm-return array{result: MovementResult|null, variant: non-empty-string}[] $results
     */
    public function markDefective(array $rows): array
    {
        return $this->processNamedCascade($rows, 'defect');
    }

    /**
     * Discard defective stock.
     *
     * @param list<TRow> $rows
     *
     * @psalm-return array{result: MovementResult|null, variant: non-empty-string}[] $results
     */
    public function discardDefective(array $rows): array
    {
        return $this->processNamedCascade($rows, 'discard');
    }

    // /** @param list<TRow> $optsAndQuantities */
    // public static function moveBooked(&$optsAndQuantities, $accountId, $zone)
    // {
    //     return self::moveStock(
    //         optsAndQuantities: $optsAndQuantities,
    //         movePath: self::getMovePath('fs', 'sd', $zone, true),
    //         moveTypeName: 'SO',
    //         refId: $orderId,
    //         options: [
    //             'log_adminId'         => 1,
    //              // no need to port $failOnUnspentQty. In the new implementation, the movement engine will simply
    //              // move as much as possible up to the requested quantity, and return the unspent quantity in the
    //              // MovementResult, so the caller can decide how to handle it.
    //             'failOnUnspentQty'    => $failOnUnspentQty,
    //             'newStockHasZeroQty'  => true,
    //             'setStockMovesOnOpts' => true,
    //         ],
    //     );
    // }

    // // Can be used to cancel boutique purchases anytime
    // // Can be used for consignment products ONLY while sale is ongoing (with limitFsByIfs)
    // public static function moveCancel(&$optsAndQuantitiesByStockType, $zone, $liveConsignment, $unCancel, $logId, $EUPoStatus) {
    //     list($from, $to) = $unCancel ? ['fs', 'sd'] : ['sd', 'fs'];
    //     $moveOptions = ['newStockHasZeroQty' => true, 'setStockMovesOnOpts' => true/*, 'limitFsByIfs' => true*/];
    //     $cancelMoves = new RecordSet();

    //     foreach ($optsAndQuantitiesByStockType as $stockType => &$optsAndQuantities) {
    //         // cancel has furthest first priority, uncancelling has nearest-first priority
    //         $movePath = self::getMovePath($from, $to, $zone, $unCancel, $stockType);
    //         if ($liveConsignment) {
    //             // limitFsByIfs might be necessary only to avoid RARE (impossible) situations such as:
    //             // 1 unit of A sold in past sale X; sup stock from X is not yet arrived and we do sale Y with same
    //             // product. A is sold again, but from CH stock, then canceled. The cancelation would move from sup_sd 1st.
    //         } else {
    //             // if products are in an EU
    //             if ($zone == 'eu' && $EUPoStatus > 0) {
    //                 // if PO and it's not arrived, don't move stock (not allowed)
    //                 if ($EUPoStatus < 5) return false;
    //                 // otherwise (goods are received), move EU stock, don't touch CH or SUP stock
    //                 $movePath = $unCancel
    //                     ? ['eu' => ['from' => ['eu', 'sd'], 'to' => ['eu','fs']]]
    //                     : ['eu' => ['from' => ['eu', 'fs'], 'to' => ['eu', 'sd']]];
    //             } elseif (isset($movePath['supC'])) {
    //                 // Special case when handling a cancel of sup stock outside of live consignment sale
    //                 if ($unCancel) {
    //                     // when un-canceling, we can add to supC_sd but can't remove 'from' supC_fs
    //                     $movePath['supC']['from'] = null;
    //                 } else {
    //                     // When canceling we can deduct from what was sold, but we can't put back to fs stock
    //                     // because supC_fs stock doesn't "exist" outside of a live consignment sale
    //                     $movePath['supC']['to'] = null;
    //                 }
    //             }
    //         }
    //         $un = $unCancel ? 'un' : '';
    //         $newMoves = self::moveStock($optsAndQuantities, $movePath, $un.'cancel', $logId, $moveOptions);
    //         if ($newMoves) $cancelMoves->pushRecordset($newMoves);
    //     }

    //     return $cancelMoves;
    // }

    // // Remove from 'sd', supplier-first, when adding a 'missing' or 'defect'
    // public static function moveFromSold(&$optsAndQuantitiesByStockType, $zone, $reverse, $refId) {
    //     list($from, $to) = $reverse ? [null, 'sd'] : ['sd', null];
    //     $un = $reverse ? 'un' : '';
    //     $moveOptions = ['newStockHasZeroQty' => true, 'setStockMovesOnOpts' => true];
    //     $stockMovements = new RecordSet();
    //     foreach ($optsAndQuantitiesByStockType as $stockType => &$optsAndQuantities) {
    //         $movePath = self::getMovePath($from, $to, $zone, $reverse, $stockType);
    //         $newMoves = self::moveStock($optsAndQuantities, $movePath, $un.'missing', $refId, $moveOptions);
    //         if ($newMoves) $stockMovements->pushRecordset($newMoves);
    //     }
    //     return $stockMovements;
    // }

    // // Accepted return where products can go back to stock
    // public static function moveReturn(&$optsAndQuantitiesByStockType, $zone, $reverse, $logId) {
    //     $moveOptions = ['newStockHasZeroQty' => true, 'setStockMovesOnOpts' => true];
    //     $direction = $reverse ? 'from' : 'to';
    //     $un = $reverse ? 'un' : '';
    //     $stockMovements = new RecordSet();
    //     foreach ($optsAndQuantitiesByStockType as $stockType => &$optsAndQuantities) {
    //         $locCode = $zone === 'eu' ? 'eu' : "ch$stockType";
    //         $movePath = [[$direction => [$locCode, 'fs']]];
    //         $newMoves = self::moveStock($optsAndQuantities, $movePath, $un."return", (int)$logId, $moveOptions);
    //         if ($newMoves) $stockMovements->pushRecordset($newMoves);
    //     }
    //     return $stockMovements;
    // }

    // //
    // public static function moveShip($optIdsAndQuantitiesByLocCode, $reverse, $logId) {
    //     $allStockMovements = new RecordSet();
    //     $un = $reverse ? 'un' : '';

    //     foreach ($optIdsAndQuantitiesByLocCode as $locCode => $optIdsAndQuantities) {
    //         $direction = $reverse ? 'to' : 'from';
    //         $movePath = [[$direction => [$locCode, 'sd']]];
    //         $moveOptions = ['failOnUnspentQty' => true, 'newStockHasZeroQty' => true];
    //         $stockMovements = self::moveStock($optIdsAndQuantities, $movePath, $un."shipping", (int)$logId, $moveOptions);

    //         if (self::$insufficientQtyErrorOpts)
    //             return false;

    //         $allStockMovements->pushRecordSet($stockMovements);
    //     }

    //     return $allStockMovements;
    // }

    // /**
    //  * will move existing stock from one {loc}_{state} to another (e.g. $from="sup_sd" $to="ch_sd")
    //  * Only actually existing stock quantities will be moved. No cascade will happen.
    //  */
    // public static function fullMove($optsOrOptIds, $from, $to = null, $moveTypeName = 'massManual') {
    //     list($fromLoc, $fromState) = explode('_', $from);
    //     list($toLoc, $toState) = explode('_', $to);
    //     $optsAndQuantities = array_map(function($poid) { return [$poid]; }, $optsOrOptIds);
    //     $movePath = [['from' => [$fromLoc, $fromState], 'to' => [$toLoc, $toState]]];
    //     $moveOptions = ['useSourceQty' => true];
    //     $stockMovements = self::moveStock($optsAndQuantities, $movePath, $moveTypeName, 0, $moveOptions);
    //     return $stockMovements;
    // }

    // public static function intersaleReset($optsOrOptIds) {
    //     foreach (['fs', 'ifs'] as $fromState) {
    //         $optsAndQuantities = array_map(function($poid) { return [$poid]; }, $optsOrOptIds);
    //         $movePath = [['from' => ['supC', $fromState], 'to' => null]];
    //         $moves = self::moveStock($optsAndQuantities, $movePath, 'intersaleMoveReset', 0, ['useSourceQty' => true]);
    //         $moves->saveAll();
    //     }
    // }

    /**
     * @psalm-type Row array{loc: non-empty-string, own: non-empty-string, fs: int, sd: int}
     *
     * @param array<Row> $rows
     *
     * @return MovementResult
     */
    public function singelIngress(array $rows, int $quantity)
    {
        $inventory = Inventory::fromRows(
            $this->space,
            $rows,
            /** @param array{loc: non-empty-string, own: non-empty-string, fs: int, sd: int} $row */
            fn ($row, SlotSpace $_space) => [
                [$this->space->slot(['loc' => $row['loc'], 'own' => $row['own'], 'state' => 'fs']), $row['fs']],
                [$this->space->slot(['loc' => $row['loc'], 'own' => $row['own'], 'state' => 'sd']), $row['sd']],
            ],
        );
        $cascade = Cascade::define('single-ingress', static fn (Cascade $cascade) => $cascade
            ->move('*.*.sd', '*.*.fs'));

        return (new MovementEngine())->execute($inventory, $this->space, $cascade, $quantity);
    }

    /**
     * @param list<TRow>       $rows
     * @param non-empty-string $name
     *
     * @psalm-return array{result: MovementResult|null, variant: non-empty-string}[] $results
     */
    /**
     * @param list<TRow>       $rows
     * @param non-empty-string $name
     *
     * @psalm-return array{result: MovementResult|null, variant: non-empty-string}[] $results
     */
    private function processNamedCascade(array $rows, string $name): array
    {
        return $this->processBatch($rows, $this->space->getCascade($name));
    }

    // public function testReceivePurchaseOrder(): void
    // {
    //     // ** @var array<TRow> $rows */
    //     $rows = [
    //         ['var' => 'A', 'mvQtty' => 10, 'loc' => 'sup', 'own' => 'C', 'fs' => 10, 'sd' => 10],
    //         ['var' => 'A', 'mvQtty' => 10, 'loc' => 'wh1', 'own' => 'C', 'fs' => 20, 'sd' => 01],
    //         ['var' => 'A', 'mvQtty' => 10, 'loc' => 'sup', 'own' => 'C', 'fs' => 30, 'sd' => 10],
    //         ['var' => 'A', 'mvQtty' => 10, 'loc' => 'wh1', 'own' => 'C', 'fs' => 40, 'sd' => 01],
    //         ['var' => 'B', 'mvQtty' => 13, 'loc' => 'sup', 'own' => 'C', 'fs' => 50, 'sd' => 20],
    //         ['var' => 'B', 'mvQtty' => 13, 'loc' => 'wh1', 'own' => 'C', 'fs' => 60, 'sd' => 20],
    //         ['var' => 'B', 'mvQtty' => 13, 'loc' => 'sup', 'own' => 'C', 'fs' => 70, 'sd' => 20],
    //         ['var' => 'B', 'mvQtty' => 13, 'loc' => 'wh1', 'own' => 'C', 'fs' => 80, 'sd' => 20],
    //     ];

    //     // This is an purchase order reception, where we move items from supplier to warehouse.
    //     // If quantity received is higher than quantity ordered (mvQtty > sup-sd), the excess goes to
    //     // warehouse as forsale (quantity added to `fs`).
    //     $edges = [
    //         $this->space->move('sup.C.sd', 'wh*.C.sd'),
    //     ];

    //     $cascade = Cascade::define('legacy-ingress', static fn (Cascade $cascade) => $cascade
    //         ->step('*.*.sd', '*.*.fs')
    //     );

    //     $result = $this->processBatch($rows, $plan);
    // }

    // public function outgress(InventoryBatch $batch, Repository $repo): void
    // {
    //     foreach ($batch->getResults() as ['variant' => $variant, 'result' => $result]) {
    //         if (null === $result) {
    //             continue;
    //         }

    //         foreach ($result->mutations() as $mutation) {
    //             $slot = $mutation->slot();

    //             $repo->applyDelta(
    //                 variant: $variant,
    //                 slot: $slot,
    //                 delta: $mutation->delta(),
    //             );
    //         }
    //     }
    // }
}

interface Repository
{
    public function applyDelta(string $variant, Slot $slot, int $delta): void;
}

final class FooVariant
{
    /** @param non-empty-string $id */
    public function __construct(
        public string $id,
    ) {
    }
}
