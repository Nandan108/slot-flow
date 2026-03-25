<?php

declare(strict_types=1);

/**
 * This file contains the core of MyPrivateBoutique's inventory management system,
 * originally written in 2017 and evolved over the following years.
 *
 * It is preserved here for historical and educational purposes, as the foundation
 * from which the SlotFlow engine was later extracted and generalized.
 *
 * This implementation reflects a real-world, production-driven design, where multiple
 * concerns ended up being tightly coupled within a single class, including:
 *
 * - Stock representation (ProductOptStock as both data model and behavior carrier)
 * - Business rules governing stock movements (moveReceivePO, moveBooked, moveCancel, etc.)
 * - Movement execution engine (moveStock)
 * - Movement logging and audit trail (logMovements)
 * - Input normalization and data preparation (prepareOpts)
 * - Retrieval and computation of quantity available for purchase (getAvailableStock)
 *
 * While functional and battle-tested, this design mixes domain modeling, workflow logic,
 * and infrastructure concerns, making it difficult to reason about, extend, or reuse.
 *
 * SlotFlow is a re-implementation of these ideas as a generic, composable flow engine,
 * separating state representation, movement planning, constraints, and execution.
 */

namespace MPB\Shop\Catalogue\Model;

use MPB\Base\DbTable\RecordSet;
use MPB\Base\DI;
use MPB\Utils;
use MPB\Utils\debug;

class ProductOptStock extends \MPB\Base\DbTable\Record
{
    public const AUTO_CREATE_MISSING_OPTIONS = 1;
    public const AUTO_SAVE_CREATED_OPTIONS = 2;
    // to hold reference to product
    public $productOpt;
    public $changeLog = [];
    public static $insufficientQtyErrorOpts;
    public static $fieldsByShort = [
        'fs'  => 'forsale',
        'ifs' => 'init', // 'initial for sale' stock, used for consignment products to limit the quantity that can be moved to 'forsale'
        'sd'  => 'sold',
    ];

    protected static $tableInfo = [
        'name'         => 'shop_product_opt_stock',
        'key'          => 'id',
        'foreign_keys' => ['opt_id', 'loc_id'],
        'fields'       => [
            'id'                => ['INT(10) unsigned', 'signed' => false, 'null' => true, 'auto_increment' => true],
            'opt_id'            => ['INT(10) unsigned'],
            'loc_id'            => ['TINYINT(10) UNSIGNED'],
            'forsale'           => ['SMALLINT(6) NOT NULL'],
            'sold'              => ['SMALLINT(6) NOT NULL'],
            'incart'            => ['SMALLINT(6) NOT NULL'],
            'init'              => ['SMALLINT(6) NOT NULL'],
        ],
        'relations' => [
            'productOpt' => [
                'type'  => 'manyToOne',
                'class' => '\MPB\Shop\Catalogue\Model\ProductOpt', // Product variants table
                'key'   => 'opt_id',
            ],
        ],
    ];

    /**
     * returns stock available for purchase at a given $zone in the form of {
     *   totForsale: number,
     *   rows: RecordSet<ProductOptStock>,
     * }.
     */
    public static function getAvailableStock($opt_id, $zone)
    {
        // get list of stock rows corresponding to opt_id
        // that are visible in $zone
        $rows = new RecordSet(array_map(
            function ($row) { return new ProductOptStock($row); },
            self::db()->execute("
            SELECT pos.*
            FROM shop_product_opt_stock pos
                JOIN shop_product_stock_locations psl ON pos.loc_id = psl.id
            WHERE pos.opt_id = $opt_id
                AND pos.forsale > 0
                AND psl.{$zone}_priority > 0
            ORDER BY psl.{$zone}_priority
          ")->getRows(),
        ));

        // get sum of stock amount available for purchase in $zone
        for ($i = $totForsale = 0; $i < count($rows); ++$i) {
            $totForsale += $rows[$i]->forsale;
        }

        return (object) compact('totForsale', 'rows');
    }

    /**
     * When PO stock is received moveStock([sup, ch], 'sd' => [sd, fs])
     *    move(sup_sd => ch_sd, null => ch_fs)
     * When EU PO stock is received
     *    move(ch_sd => eu_sd)
     * moveBooked(eu_fs => eu_sd, overflow: ch_fs => ch_sd, overflow: sup_fs => sup_sd, overflow: fail))).
     * // moveCancel starts with sup only if product is within sale
     * // moveCancel is bound by ifs only if product is within sale
     * moveCancel(sup_sd => sup_fs[!>ifs], overflow: ch_sd => ch_fs[!>ifs], overflow: eu_sd => eu_fs)
     * moveMissing(sup_sd => null, overflow: ch_sd => null, overflow: eu_sd => null, overflow: ignore)
     * moveUnMissing(null => eu_sd[!>ifs], null => eu_sd[!>ifs], null => ch_sd[!>ifs], null => sup_sd[!>ifs], overflow: fail)
     * moveReturn(null => eu_fs)
     * moveUnReturn(eu_fs => null)
     * moveOrder(eu_sd => null).
     *
     * $optsAndQuantities : array of [$prod: ProductOpt, $qtty: number]
     * $failOnUnspentQty: boolean (default to false)
     * $limitFsByIfs: boolean (default to false)
     * $options : {
     *    'moves' => ['from' => [loc, state], 'to' => [loc, state]][]
     *    'fromState' => 'sd' | 'fs' | null
     *    'toState' => 'sd' | 'fs' | null
     *    'nearestFirst' => boolean, defaults to (fromState == sd)
     *    '' => truthy (default) or falsy
     */
    public static function getLocalLoc()
    {
        return array_pop(array_keys(ProductStockLocation::getByCode(null, DI::get('locale')->zone)));
    }

    public static function logMovements($moveTypeIdOrName, $adminId, $refId, $changedStock)
    {
        $stockStates = ['forsale' => 'fs', 'sold' => 'sd']; // we don't log changes to 'ifs' field
        if (!$changedStock) {
            return;
        }

        if (!is_numeric($moveTypeId = $moveTypeIdOrName)) {
            $moveTypeId = ProductStockMovetype::getIdByName($moveTypeIdOrName);
        }

        // distribute movements over their $opts->stock[]
        $opts = $changedStock->getProductOpt();
        $products = $opts->getProduct();
        foreach ($changedStock as $cs) {
            $cs->productOpt->stock[] = $cs;
        }

        $logEntries = [];
        foreach ($opts as $opt) {
            foreach ($opt->stock as $stockLine) {
                $changed = $stockLine->isDirty(true);
                // for each field (forsale and sold) of each stock of each opt
                foreach ($stockStates as $dbField => $stateCode) {
                    // if the value was changed
                    if ($changed[$dbField] && ($diff = $changed[$dbField][1] - $changed[$dbField][0])) {
                        $logEntries[] = $stockLine->changeLog[] = ProductStockMovelog::getNew([
                            'type_id'     => $moveTypeId, // needs conversion
                            'ref_id'      => $refId ?: 0,
                            'actor_id'    => $adminId,
                            'product_id'  => $opt->product_id,
                            'opt_key'     => $opt->opt_key,
                            'sizetype_id' => $opt->product->sizetype_id,
                            'loc_id'      => $stockLine->loc_id,
                            'stt'         => $stateCode,
                            'qtty'        => $diff,
                            'balance'     => $changed[$dbField][1],
                        ]);
                    }
                }
                // mark the FaCategory product cache to be refreshed
                if (isset($changed['forsale']) && min($changed['forsale']) < 2) {
                    $cacheRefreshPids[$opt->product_id] = true;
                }
            }
        }
        // save logEntries if we have any
        if ($logEntries) {
            $logEntries = RecordSet::getNew($logEntries)->saveAll();
        }
        // mark products as updated
        $products->setAll(['updateTime' => date('Y-m-d H:i:s')])->saveAll();

        // refresh FaCategory cache if necessary
        if ($cacheRefreshPids) {
            Category::refreshCache(['product_ids' => array_keys($cacheRefreshPids ?? [])]);
        }
    }

    /**
     * input: &$data = [[ $optIdx => $opt | $optId | [$prodId, $opt_key] ]]
     * output: &$data = [[ $optIdx => $opt ]]
     * returns Recordset($opts);.
     */
    public static function prepareOpts(&$data, $optIdx = 0, $flags = null)
    {
        $opts = Utils::pluck($data, $optIdx);
        $flags = $flags ?? self::AUTO_CREATE_MISSING_OPTIONS | self::AUTO_SAVE_CREATED_OPTIONS;

        // convert opt_id to opt
        if ($optIds = array_filter($opts, function ($id) { return is_numeric($id); })) {
            $optsById = ProductOpt::find(['id' => ['IN', implode(',', $optIds)]])->recordsByKey();
            foreach ($optIds as $i => $optId) {
                if (!($opt = $optsById[$optId])) {
                    throw new \Exception("Unable to move stock for ProductOpt #$optId (option not found)");
                }
                $data[$i][$optIdx] = $opt;
            }
        }

        // convert [prodId, optKey] to opt.
        if ($prodIdAndKeys = array_filter($opts, function ($id) { return is_array($id); })) {
            $db = self::db();
            $tmpTableName = '_prepareOpts_'.uniqid();
            $values = '('.implode('),(', array_map(function ($prodIdAndKey) use ($db) {
                return ((int) $prodIdAndKey[0]).', '.$db->Quote($prodIdAndKey[1]);
            }, $prodIdAndKeys)).')';
            $db->execute("CREATE TEMPORARY TABLE $tmpTableName (product_id int, opt_key varchar(16)) COLLATE utf8mb4_unicode_ci ENGINE=MEMORY");
            $db->execute("INSERT INTO $tmpTableName VALUES $values");
            $foundOpts = ProductOpt::find("(product_id, opt_key) in (SELECT * from $tmpTableName)");
            foreach ($foundOpts as $opt) {
                $optsByProdIdAndOptKey[$opt->product_id.$opt->opt_key] = $opt;
            }
            foreach ($prodIdAndKeys as $i => $prodIdAndKey) {
                if (!($opt = $optsByProdIdAndOptKey[implode('', $prodIdAndKey)])) {
                    if ($flags & self::AUTO_CREATE_MISSING_OPTIONS) {
                        $opt = $optsByProdIdAndOptKey[implode('', $prodIdAndKey)] = (new ProductOpt([
                            'product_id' => $prodIdAndKey[0], 'opt_key' => $prodIdAndKey[1],
                        ]));
                        if ($flags & self::AUTO_SAVE_CREATED_OPTIONS) {
                            $opt->save();
                        }
                    } else {
                        throw new \Exception("Unable to move stock for ProductOpt #$prodIdAndKey[0] (option not found)");
                    }
                }
                $data[$i][$optIdx] = $opt;
            }
        }

        return new RecordSet(Utils::pluck($data, $optIdx));
    }

    public static function getMovePath($fromState, $toState, $priorityZone, $nearestFirst = false, $stockType = null)
    {
        $locs = ProductStockLocation::getByCode(null, $priorityZone);
        // if a stock type (C or FP) is specified, keep only those locs
        if (null !== $stockType) {
            $locs = array_filter($locs, function ($loc) use ($stockType) {
                // return ($loc->type ?: $stockType) === $stockType;
                return $loc->type === $stockType;
            });
        }

        $locPath = array_keys($locs);
        if ($nearestFirst) {
            $locPath = array_reverse($locPath);
        }
        foreach ($locPath as $loc) {
            $movePath[$loc] = $move = ['from' => [$loc, $fromState], 'to' => [$loc, $toState]];
        }

        return $movePath;
    }

    /**
     * takes a ProductOpt recordset and returns a
     * function ($opt_id, $stockLocKey) => existing stock line or new ProductOptStock().
     */
    public static function makeStockLineGetter($opts, $newStockDefaultVal = 0)
    {
        $optStock = $opts->getStock()->recordsGroupedByKey('opt_id');
        foreach ($optStock as $opt_id => &$s) {
            $s = $s->recordsByKey('loc_id');
        }

        $locsByKey = ProductStockLocation::getByCode() + ProductStockLocation::getById();
        $newStockDefaults = [
            'forsale' => $newStockDefaultVal,
            'sold'    => $newStockDefaultVal,
            'init'    => $newStockDefaultVal,
        ];
        $getStockLine = function ($opt_id, $locKey) use ($locsByKey, &$optStock, $newStockDefaults) {
            $loc_id = $locsByKey[$locKey]->id;
            if (!($stock = $optStock[$opt_id][$loc_id] ?? null)) {
                return $optStock[$opt_id][$loc_id] =
                    self::getNew(compact('opt_id', 'loc_id'))
                        ->set($newStockDefaults);
            }

            return $stock;
        };

        return $getStockLine;
    }

    /**
     * expects $optsQtyLocType == array of [$opt|$optId, $quantity, $locCode|$locId, 'fs'|'sd'|'ifs']
     * $additionMode.
     */
    public static function setStock($optsQtyLocType, $moveTypeName, $refId = 0, $admin_id = null, $additionMode = false)
    {
        $getStockLine = self::makeStockLineGetter(self::prepareOpts($optsQtyLocType));

        $changedStock = new RecordSet();
        foreach ($optsQtyLocType as $data) {
            list($opt, $qty, $locKey, $type) = $data;
            $stock = $getStockLine($opt->id, $locKey);
            $field = self::$fieldsByShort[$type];
            $newQty = $qty + ($additionMode ? $stock->$field : 0);
            if (($stock->$field ?? 0) !== $newQty) {
                $stock->$field = $newQty;
                $changedStock->records[] = $stock;
            }
        }

        if ($changedStock->records) {
            $adminId = $admin_id ?? DI::get('adminSession')->account_id ?? 1;
            self::logMovements($moveTypeName, $adminId, $refId, $changedStock);
            $changedStock->saveAll();
        }

        return $changedStock;
    }

    public static function moveReceivePO($optsAndQuantities, $type, $toZone, $poId, $reverse)
    {
        if ('ch' === $toZone) {
            if ('C' === $type) {
                $movePath = [
                    ['from' => ['supC', 'sd'], 'to' => ['chC',  'sd']],
                    // when we received extra stock not already booked by a
                    // customer, move it to "for sale"
                    ['to' => ['chC', 'fs']],
                ];
            } else {
                $movePath = [
                    // stock that was bought: supFP_sd => chFP_sd
                    ['from' => ['supFP', 'sd'], 'to' => ['chFP', 'sd']],
                    // stock that was not bought yet: supFP_fs => chFP_fs
                    ['from' => ['supFP', 'fs'], 'to' => ['chFP', 'fs']],
                    // any additional received quantities also go to chFP_fs
                    ['to' => ['chFP', 'fs']],
                ];
            }
        } else { // $toZone == 'eu'
            $movePath = [
                ['from' => ["ch$type", 'sd'], 'to' => ['eu', 'sd']],
                // extra quantities should never happen in an EU PO
                // ... but we'll allow for it anyways
                ['to' => ['eu', 'fs']],
            ];
        }

        if ($reverse) {
            foreach ($movePath as $i => $m) {
                $movePath[$i]['from'] = $m['to'] ?: null;
                $movePath[$i]['to'] = $m['from'] ?: null;
            }
            $movePath = array_reverse($movePath, true);
        }

        $stockMovements = self::moveStock($optsAndQuantities, $movePath, 'PO', $poId, ['newStockHasZeroQty'=>true]);

        return $stockMovements;
    }

    public static function moveBooked(&$optsAndQuantities, $accountId, $zone, $failOnUnspentQty, $orderId)
    {
        return self::moveStock(
            optsAndQuantities: $optsAndQuantities,
            movePath: self::getMovePath('fs', 'sd', $zone, true),
            moveTypeName: 'SO',
            refId: $orderId,
            options: [
                'log_adminId'         => 1,
                'failOnUnspentQty'    => $failOnUnspentQty,
                'newStockHasZeroQty'  => true,
                'setStockMovesOnOpts' => true,
            ],
        );
    }

    // Can be used to cancel boutique purchases anytime
    // Can be used for consignment products ONLY while sale is ongoing (with limitFsByIfs)
    public static function moveCancel(&$optsAndQuantitiesByStockType, $zone, $liveConsignment, $unCancel, $logId, $EUPoStatus)
    {
        list($from, $to) = $unCancel ? ['fs', 'sd'] : ['sd', 'fs'];
        $moveOptions = ['newStockHasZeroQty' => true, 'setStockMovesOnOpts' => true/* , 'limitFsByIfs' => true */];
        $cancelMoves = new RecordSet();

        foreach ($optsAndQuantitiesByStockType as $stockType => &$optsAndQuantities) {
            // cancel has furthest first priority, uncancelling has nearest-first priority
            $movePath = self::getMovePath($from, $to, $zone, $unCancel, $stockType);
            if ($liveConsignment) {
                // limitFsByIfs might be necessary only to avoid RARE (impossible) situations such as:
                // 1 unit of A sold in past sale X; sup stock from X is not yet arrived and we do sale Y with same
                // product. A is sold again, but from CH stock, then canceled. The cancelation would move from sup_sd 1st.
            } else {
                // if products are in an EU
                if ('eu' === $zone && $EUPoStatus > 0) {
                    // if PO and it's not arrived, don't move stock (not allowed)
                    if ($EUPoStatus < 5) {
                        return false;
                    }
                    // otherwise (goods are received), move EU stock, don't touch CH or SUP stock
                    $movePath = $unCancel
                        ? ['eu' => ['from' => ['eu', 'sd'], 'to' => ['eu', 'fs']]]
                        : ['eu' => ['from' => ['eu', 'fs'], 'to' => ['eu', 'sd']]];
                } elseif (isset($movePath['supC'])) {
                    // Special case when handling a cancel of sup stock outside of live consignment sale
                    if ($unCancel) {
                        // when un-canceling, we can add to supC_sd but can't remove 'from' supC_fs
                        $movePath['supC']['from'] = null;
                    } else {
                        // When canceling we can deduct from what was sold, but we can't put back to fs stock
                        // because supC_fs stock doesn't "exist" outside of a live consignment sale
                        $movePath['supC']['to'] = null;
                    }
                }
            }
            $un = $unCancel ? 'un' : '';
            $newMoves = self::moveStock($optsAndQuantities, $movePath, $un.'cancel', $logId, $moveOptions);
            if ($newMoves) {
                $cancelMoves->pushRecordset($newMoves);
            }
        }

        return $cancelMoves;
    }

    // Remove from 'sd', supplier-first, when adding a 'missing' or 'defect'
    public static function moveFromSold(&$optsAndQuantitiesByStockType, $zone, $reverse, $refId)
    {
        list($from, $to) = $reverse ? [null, 'sd'] : ['sd', null];
        $un = $reverse ? 'un' : '';
        $moveOptions = ['newStockHasZeroQty' => true, 'setStockMovesOnOpts' => true];
        $stockMovements = new RecordSet();
        foreach ($optsAndQuantitiesByStockType as $stockType => &$optsAndQuantities) {
            $movePath = self::getMovePath($from, $to, $zone, $reverse, $stockType);
            $newMoves = self::moveStock($optsAndQuantities, $movePath, $un.'missing', $refId, $moveOptions);
            if ($newMoves) {
                $stockMovements->pushRecordset($newMoves);
            }
        }

        return $stockMovements;
    }

    // Accepted return where products can go back to stock
    public static function moveReturn(&$optsAndQuantitiesByStockType, $zone, $reverse, $logId)
    {
        $moveOptions = ['newStockHasZeroQty' => true, 'setStockMovesOnOpts' => true];
        $direction = $reverse ? 'from' : 'to';
        $un = $reverse ? 'un' : '';
        $stockMovements = new RecordSet();
        foreach ($optsAndQuantitiesByStockType as $stockType => &$optsAndQuantities) {
            $locCode = 'eu' === $zone ? 'eu' : "ch$stockType";
            $movePath = [[$direction => [$locCode, 'fs']]];
            $newMoves = self::moveStock($optsAndQuantities, $movePath, $un.'return', (int) $logId, $moveOptions);
            if ($newMoves) {
                $stockMovements->pushRecordset($newMoves);
            }
        }

        return $stockMovements;
    }

    public static function moveShip($optIdsAndQuantitiesByLocCode, $reverse, $logId)
    {
        $allStockMovements = new RecordSet();
        $un = $reverse ? 'un' : '';

        foreach ($optIdsAndQuantitiesByLocCode as $locCode => $optIdsAndQuantities) {
            $direction = $reverse ? 'to' : 'from';
            $movePath = [[$direction => [$locCode, 'sd']]];
            $moveOptions = ['failOnUnspentQty' => true, 'newStockHasZeroQty' => true];
            $stockMovements = self::moveStock($optIdsAndQuantities, $movePath, $un.'shipping', (int) $logId, $moveOptions);

            if (self::$insufficientQtyErrorOpts) {
                return false;
            }

            $allStockMovements->pushRecordSet($stockMovements);
        }

        return $allStockMovements;
    }

    /**
     * will move existing stock from one {loc}_{state} to another (e.g. $from="sup_sd" $to="ch_sd")
     * Only actually existing stock quantities will be moved. No cascade will happen.
     */
    public static function fullMove($optsOrOptIds, $from, $to = null, $moveTypeName = 'massManual')
    {
        list($fromLoc, $fromState) = explode('_', $from);
        list($toLoc, $toState) = explode('_', $to);
        $optsAndQuantities = array_map(function ($poid) { return [$poid]; }, $optsOrOptIds);
        $movePath = [['from' => [$fromLoc, $fromState], 'to' => [$toLoc, $toState]]];
        $moveOptions = ['useSourceQty' => true];
        $stockMovements = self::moveStock($optsAndQuantities, $movePath, $moveTypeName, 0, $moveOptions);

        return $stockMovements;
    }

    public static function intersaleReset($optsOrOptIds)
    {
        foreach (['fs', 'ifs'] as $fromState) {
            $optsAndQuantities = array_map(function ($poid) { return [$poid]; }, $optsOrOptIds);
            $movePath = [['from' => ['supC', $fromState], 'to' => null]];
            $moves = self::moveStock($optsAndQuantities, $movePath, 'intersaleMoveReset', 0, ['useSourceQty' => true]);
            $moves->saveAll();
        }
    }

    // $optsAndQuantities may be an array of [ProductOpt, quantity] or [opt_id, quantity]
    public static function moveStock(&$optsAndQuantities, $movePath, $moveTypeName, $refId, $options = [])
    {
        if (!$optsAndQuantities) {
            return new RecordSet();
        }

        // if we have [opt_id, quantity], convert to [ProductOpt, quantity]
        $opts = self::prepareOpts($optsAndQuantities);

        // debug::print_r(compact('allStock'));
        $newStockLines = [];

        $useSourceQty = $options['useSourceQty'] ?? false;
        // when $limitFsByIfs, each move is limited such that after the
        // move, the destination field may not be contain a quantity higher
        // than 'init' field.
        $limitFsByIfs = $options['limitFsByIfs'] ?? false;
        // When $failOnUnspentQty the function will fail (return false)
        // if a qty could not be moved it its entirety
        $failOnUnspentQty = $options['failOnUnspentQty'] ?? false;
        // A stock movement to or from a currently inexistent stock line
        // will generate a new stock object to represent this the start or endpoint
        // of this move. This new stock will, by default, have null (not zero) quantities.
        // Moving stock from a null qty is allowed for the full qty to be moved. The new stock,
        // will have zero qty after the move.
        // When $newStockHasZeroQty, new stock is initialized with fs=ifs=sd=0,
        // which will prevent any quantity movement out of new stock.
        $newStockDefaultVal = ($options['newStockHasZeroQty'] ?? false) ? 0 : null;
        // To associate move stock to each opt
        $setStockMovesOnOpts = $options['setStockMovesOnOpts'] ?? false;

        $getStockLine = self::makeStockLineGetter($opts, $newStockDefaultVal);
        self::$insufficientQtyErrorOpts = null;
        $changedStock = [];

        foreach ($optsAndQuantities as &$optAndQty) {
            list($opt, $startQty) = $optAndQty; // $opt and $qty

            if (!($qty = $startQty) && !$useSourceQty) {
                continue;
            } // nothing to move

            $stockKey = function ($s) { return $s->opt_id.'-'.$s->loc_id; };
            foreach ($movePath as $mp) {
                unset($fromStock, $from, $toStock, $to);

                list($fromLoc, $fromState) = $mp['from'] ?? [null, null];
                if ($fromLoc && $fromState) {
                    $fromStock = $getStockLine($opt->id, $fromLoc);
                    $fromField = self::$fieldsByShort[$fromState];
                    if (!$fromField) {
                        throw new \Exception("unknown stock state: \"$fromState\"");
                    }
                }

                // when moving from a newly created stock, assume it contains
                // exactly the quantity we're moving from it, so it'll be zero
                // when we finish.
                if ($fromStock) {
                    $fromStock->$fromField = $fromStock->$fromField ?? $qty;
                    $movable = $useSourceQty
                        ? $fromStock->$fromField
                        : min($fromStock->$fromField, $qty);
                } else {
                    $movable = $useSourceQty ? 0 : $qty;
                }

                if (!$movable) {
                    continue;
                }

                list($toLoc, $toState) = $mp['to'] ?? [null, null];
                if ($toLoc && $toState) {
                    $toStock = $getStockLine($opt->id, $toLoc);
                    if (!($toField = self::$fieldsByShort[$toState])) {
                        throw new \Exception("unknown stock state: \"$toState\"");
                    }
                    if ($limitFsByIfs) {
                        $movable = max(0, min($movable, $toStock->init - $toStock->$toField));
                    }
                }

                if (!$movable) {
                    continue;
                }

                if ($fromStock) {
                    $fromStock->$fromField -= $movable;
                    $changedStock[$stockKey($fromStock)] = $fromStock;
                    if ($setStockMovesOnOpts) {
                        $optAndQty['moves'][$stockKey($fromStock)] = $fromStock;
                    }
                }
                if ($toStock) {
                    $toStock->$toField += $movable;
                    $changedStock[$stockKey($toStock)] = $toStock;
                    if ($setStockMovesOnOpts) {
                        $optAndQty['moves'][$stockKey($toStock)] = $toStock;
                    }
                }

                if (!($qty -= $movable)) {
                    break;
                }
            }

            if ($qty && $failOnUnspentQty) {
                $opt_key = $opt->opt_key;
                $restQty = $qty;
                $movableQty = $startQty - $qty;
                self::$insufficientQtyErrorOpts[$opt->id] = compact('opt_key', 'movableQty', 'restQty', 'opt');
            }
        }

        if (self::$insufficientQtyErrorOpts) {
            return false;
        }

        if (!($adminId = $options['log_adminId'])) {
            $admin = DI::get('adminSession')->getAccount();
            $adminId = $admin ? $admin->id : 1;
        }

        $changedStock = new RecordSet($changedStock);

        self::logMovements($moveTypeName, $adminId, $refId, $changedStock);

        return $changedStock;
    }
}
