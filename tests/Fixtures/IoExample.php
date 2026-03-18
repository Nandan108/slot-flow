<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Nandan108\SlotFlow\BatchMovementEngine;
use Nandan108\SlotFlow\Inventory;
use Nandan108\SlotFlow\InventoryBatch;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\MovementPath;
use Nandan108\SlotFlow\MovementPlan;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\SlotKey;
use Nandan108\SlotFlow\SlotSpace;

/**
 * @psalm-type VariantType non-empty-string
 * @psalm-type TRow array{var: VariantType, mvQtty: int, loc: string, own: string, fs: int, sd: int}
 */
final class IoExample
{
    private SlotSpace $space;

    public function __construct()
    {
        $this->space = SlotSpace::define([
            'loc'   => ['sup', 'wh1', 'wh2'], // sup: supplier, whN: warehouse N (1 or 2)
            'own'   => ['C', 'F'],     // C: consignment, F: firm purchase
            'state' => ['inbound', 'fs', 'reserved', 'sd'],   // fs: forsale, sd: sold
        ]);
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
        return InventoryBatch::fromRows(
            space: $this->space,
            rows: $rows,
            /** @param TRow $row */
            variantGetter: fn ($row): string => $row['var'],
            slotRowGetter: function (array $row): array {
                $s = $this->space;
                /** @var TRow $row */

                return [
                    [$s->slot([$row['loc'], $row['own'], 'fs']), $row['fs']],
                    [$s->slot([$row['loc'], $row['own'], 'sd']), $row['sd']],
                ];
            },
            /** @param list<TRow> $rows */
            quantityGetter: fn (array $rows) => $rows[array_key_first($rows) ?? 0]['mvQtty'],
            /** @param VariantType $variant */
            variantIdGetter: fn (string $variant): string => $variant,
        );
    }

    /**
     * Takes database rows.
     *
     * @param list<TRow> $rows
     *
     * @psalm-return array{result: MovementResult|null, variant: non-empty-string}[] $results
     */
    public function processBatch(array $rows, MovementPlan | MovementPath $planOrPath): array
    {
        $plan = $planOrPath instanceof MovementPlan ? $planOrPath : new MovementPlan($planOrPath);

        return (new BatchMovementEngine(new MovementEngine()))
            ->execute(
                batch: $this->prepareBatch($rows),
                plan: $plan,
            )
            ->results();
    }

    /**
     * @param list<TRow> $optsAndQuantities rows (one per variant) with fields:
     *                                      `var`= variant object
     *                                      `mvQtty`= quantity to move,
     *                                      `loc`= location code,
     *                                      `own`= ownership code ('C' for consignment or 'F' for firm purchase)
     *                                      `fs`/`sd`= inventory state 'for-sale'/'sold'
     * @param 'C'|'F'    $ownership         'C' for consignment or 'F' for firm purchase
     * @param string     $locCode           A warehouse code, as defined in 'loc' dimension. E.g. 'wh1', 'wh2'
     * @param bool       $reverse           whether to reverse the movement path
     *                                      (e.g. for adjusting purchase order reception errors)
     **/
    public function moveReceivePO(array $optsAndQuantities, string $ownership, string $locCode, bool $reverse): array
    {
        $S = $this->space;
        $S->validateDimensionValues(['loc' => $locCode, 'own' => $ownership]);

        $state = match ($ownership) {
            // When receiving consignment stock,
            'C' => 'sd', // we move only what was sold (sup.C.sd => wh*.C.sd)
            // when receiving firm purchase stock
            'F' => '*', // we move everything regardless of state
        };

        $path = $S->path([
            // stock that was sold: sup.*.sd => wh*.*.sd
            ["sup.$ownership.$state", "$locCode.$ownership.$state"],
            // any additional received quantities also go to wh*.F.fs
            [null, "$locCode.$ownership.fs"],
        ], $reverse);

        return $this->processBatch($optsAndQuantities, $path);
    }

    /** @param list<TRow> $optsAndQuantities */
    public static function moveBooked(&$optsAndQuantities, $accountId, $zone)
    {
        // return self::moveStock(
        //     optsAndQuantities: $optsAndQuantities,
        //     movePath: self::getMovePath('fs', 'sd', $zone, true),
        //     moveTypeName: 'SO',
        //     refId: $orderId,
        //     options: [
        //         'log_adminId'         => 1,
        //          // no need to port $failOnUnspentQty. In the new implementation, the movement engine will simply
        //          // move as much as possible up to the requested quantity, and return the unspent quantity in the
        //          // MovementResult, so the caller can decide how to handle it.
        //         'failOnUnspentQty'    => $failOnUnspentQty,
        //         'newStockHasZeroQty'  => true,
        //         'setStockMovesOnOpts' => true,
        //     ],
        // );
    }

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
     * @psalm-type Row array{loc: string, own: string, fs: int, sd: int}
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
            /** @param array{loc: string, own: string, fs: int, sd: int} $row */
            fn ($row) => [
                [['loc' => $row['loc'], 'own' => $row['own'], 'state' => 'fs'], $row['fs']],
                [['loc' => $row['loc'], 'own' => $row['own'], 'state' => 'sd'], $row['sd']],
            ],
        );
        $path = new MovementPath(...$this->space->edgesBetween('*.sd', '*.fs'));

        return (new MovementEngine())->execute($inventory, $path, $quantity);
    }

    public function testReceivePurchaseOrder(): void
    {
        // ** @var array<TRow> $rows */
        $rows = [
            ['var' => 'A', 'mvQtty' => 10, 'loc' => 'sup', 'own' => 'C', 'fs' => 10, 'sd' => 10],
            ['var' => 'A', 'mvQtty' => 10, 'loc' => 'wh1', 'own' => 'C', 'fs' => 20, 'sd' => 01],
            ['var' => 'A', 'mvQtty' => 10, 'loc' => 'sup', 'own' => 'C', 'fs' => 30, 'sd' => 10],
            ['var' => 'A', 'mvQtty' => 10, 'loc' => 'wh1', 'own' => 'C', 'fs' => 40, 'sd' => 01],
            ['var' => 'B', 'mvQtty' => 13, 'loc' => 'sup', 'own' => 'C', 'fs' => 50, 'sd' => 20],
            ['var' => 'B', 'mvQtty' => 13, 'loc' => 'wh1', 'own' => 'C', 'fs' => 60, 'sd' => 20],
            ['var' => 'B', 'mvQtty' => 13, 'loc' => 'sup', 'own' => 'C', 'fs' => 70, 'sd' => 20],
            ['var' => 'B', 'mvQtty' => 13, 'loc' => 'wh1', 'own' => 'C', 'fs' => 80, 'sd' => 20],
        ];

        // This is an purchase order reception, where we move items from supplier to warehouse.
        // If quantity received is higher than quantity ordered (mvQtty > sup-sd), the excess goes to
        // warehouse as forsale (quantity added to `fs`).
        $edges = [
            $this->space->move('sup.C.sd', 'wh*.C.sd'),
        ];

        $plan = new MovementPlan(
            path: new MovementPath(...$this->space->edgesBetween('*.sd', '*.fs')),
        );

        $result = $this->processBatch($rows, $plan);
    }

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
    public function applyDelta(string $variant, SlotKey $slot, int $delta): void;
}

final class FooVariant
{
    /** @param non-empty-string $id */
    public function __construct(
        public string $id,
    ) {
    }
}
