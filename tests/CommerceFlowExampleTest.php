<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\BatchMovementEngine;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\Slot;
use Nandan108\SlotFlow\SlotSpace;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\CommerceFlowExample;
use Tests\Fixtures\Repository;

final class CommerceFlowExampleTest extends TestCase
{
    public function testPrepareBatchGroupsRowsByVariant(): void
    {
        $example = new CommerceFlowExample();

        $batch = $example->prepareBatch([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'C', 'inv' => ['fs' => 5, 'sd' => 1], 'ifs' => 0],
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'sup', 'own' => 'C', 'inv' => ['fs' => 3, 'sd' => 2], 'ifs' => 0],
            ['var' => 'B', 'mvQtty' => 1, 'loc' => 'wh2', 'own' => 'F', 'inv' => ['fs' => 4, 'sd' => 0], 'ifs' => 0],
        ]);

        self::assertCount(2, $batch->items());
        self::assertSame('A', $batch->items()[0]->subject);
        self::assertSame(2, $batch->items()[0]->quantity);
        self::assertSame('B', $batch->items()[1]->subject);
        self::assertSame(1, $batch->items()[1]->quantity);
    }

    public function testProcessBatchAcceptsCascade(): void
    {
        $example = new CommerceFlowExample();
        $space = $this->space($example);
        $rows = [
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'C', 'inv' => ['fs' => 1, 'sd' => 3]],
        ];

        $space->cascade('ingress', [
            ['*.*.sd', '*.*.fs'],
        ]);

        $result = $example->processBatch($rows, $space->getCascade('ingress'));
        $movement = $this->movementResult($result);

        self::assertSame(0, $movement->remaining);
        self::assertSame('(wh1.C.sd) -> (wh1.C.fs)', (string) $movement->events[0]->edge);
        self::assertSame(2, $movement->events[0]->quantity);
    }

    public function testMoveReceivePOForConsignmentMovesSoldThenOverflow(): void
    {
        $example = new CommerceFlowExample();

        $result = $example->moveReceivePO([
            ['var' => 'A', 'mvQtty' => 5, 'loc' => 'sup', 'own' => 'C', 'inv' => ['fs' => 0, 'sd' => 3]],
            ['var' => 'A', 'mvQtty' => 5, 'loc' => 'wh1', 'own' => 'C', 'inv' => ['fs' => 10, 'sd' => 1]],
        ], 'C', 'wh1', false);
        $movement = $this->movementResult($result);

        self::assertSame(0, $movement->remaining);
        $events = $movement->events;
        self::assertCount(2, $events);
        self::assertSame('(sup.C.sd) -> (wh1.C.sd)', (string) $events[0]->edge);
        self::assertSame(3, $events[0]->quantity);
        self::assertSame('(nil) -> (wh1.C.fs)', (string) $events[1]->edge);
        self::assertSame(2, $events[1]->quantity);
    }

    public function testMoveReceivePOReverseMovesWarehouseOverflowOutFirst(): void
    {
        $example = new CommerceFlowExample();

        $result = $example->moveReceivePO([
            ['var' => 'A', 'mvQtty' => 4, 'loc' => 'sup', 'own' => 'C', 'inv' => ['fs' => 0, 'sd' => 0]],
            ['var' => 'A', 'mvQtty' => 4, 'loc' => 'wh1', 'own' => 'C', 'inv' => ['fs' => 3, 'sd' => 2]],
        ], 'C', 'wh1', true);
        $movement = $this->movementResult($result);
        $events = $movement->events;

        self::assertSame(0, $movement->remaining);
        self::assertCount(2, $events);
        self::assertSame('(wh1.C.fs) -> (nil)', (string) $events[0]->edge);
        self::assertSame(3, $events[0]->quantity);
        self::assertSame('(wh1.C.sd) -> (sup.C.sd)', (string) $events[1]->edge);
        self::assertSame(1, $events[1]->quantity);
    }

    public function testSingelIngressMovesSoldBackToForsale(): void
    {
        $example = new CommerceFlowExample();

        $result = $example->singelIngress([
            ['loc' => 'wh1', 'own' => 'C', 'fs' => 1, 'sd' => 3],
        ], 2);

        self::assertSame(0, $result->remaining);
        $events = $result->events;
        self::assertCount(1, $events);
        self::assertSame('(wh1.C.sd) -> (wh1.C.fs)', (string) $events[0]->edge);
        self::assertSame(2, $events[0]->quantity);
        self::assertSame(3, $events[0]->initialFrom);
    }

    public function testReserveMovesForsaleToCart(): void
    {
        $example = new CommerceFlowExample();

        $result = $example->reserve([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'C', 'inv' => ['fs' => 5, 'res' => 1]],
        ]);
        $movement = $this->movementResult($result);

        self::assertSame(0, $movement->remaining);
        self::assertCount(1, $movement->events);
        self::assertSame('(wh1.C.fs) -> (wh1.C.res)', (string) $movement->events[0]->edge);
        self::assertSame(2, $movement->events[0]->quantity);
    }

    public function testReleaseMovesCartBackToForsale(): void
    {
        $example = new CommerceFlowExample();

        $result = $example->release([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'C', 'inv' => ['res' => 5, 'fs' => 1]],
        ]);
        $movement = $this->movementResult($result);

        self::assertSame('(wh1.C.res) -> (wh1.C.fs)', (string) $movement->events[0]->edge);
        self::assertSame(2, $movement->events[0]->quantity);
    }

    public function testSellMovesCartToSold(): void
    {
        $example = new CommerceFlowExample();

        $result = $example->book([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'C', 'inv' => ['res' => 5, 'sd' => 1]],
        ]);
        $movement = $this->movementResult($result);

        self::assertSame('(wh1.C.res) -> (wh1.C.sd)', (string) $movement->events[0]->edge);
        self::assertSame(2, $movement->events[0]->quantity);
    }

    public function testDispatchAndDeliverFollowFulfilmentStates(): void
    {
        $example = new CommerceFlowExample();

        $dispatch = $example->dispatch([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'F', 'inv' => ['sd' => 3]],
        ]);
        $dispatchMovement = $this->movementResult($dispatch);

        self::assertSame('(wh1.F.sd) -> (wh1.F.dsp)', (string) $dispatchMovement->events[0]->edge);
        self::assertSame(2, $dispatchMovement->events[0]->quantity);

        $deliver = $example->deliver([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'F', 'inv' => ['dsp' => 3]],
        ]);
        $deliverMovement = $this->movementResult($deliver);

        self::assertSame('(wh1.F.dsp) -> (wh1.F.dlv)', (string) $deliverMovement->events[0]->edge);
        self::assertSame(2, $deliverMovement->events[0]->quantity);
    }

    public function testReturnAndRestockFollowAfterDelivery(): void
    {
        $example = new CommerceFlowExample();

        $return = $example->acceptReturn([
            ['var' => 'A', 'mvQtty' => 1, 'loc' => 'wh2', 'own' => 'F', 'inv' => ['dlv' => 2]],
        ]);
        $returnMovement = $this->movementResult($return);

        self::assertSame('(wh2.F.dlv) -> (wh2.F.ret)', (string) $returnMovement->events[0]->edge);
        self::assertSame(1, $returnMovement->events[0]->quantity);

        $restock = $example->restockReturn([
            ['var' => 'A', 'mvQtty' => 1, 'loc' => 'wh2', 'own' => 'F', 'inv' => ['ret' => 2]],
        ]);
        $restockMovement = $this->movementResult($restock);

        self::assertSame('(wh2.F.ret) -> (wh2.F.fs)', (string) $restockMovement->events[0]->edge);
        self::assertSame(1, $restockMovement->events[0]->quantity);
    }

    public function testDiscardDefectiveMovesStockToNil(): void
    {
        $example = new CommerceFlowExample();

        $result = $example->discardDefective([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'C', 'inv' => ['def' => 3]],
        ]);
        $movement = $this->movementResult($result);

        self::assertSame(0, $movement->remaining);
        self::assertCount(1, $movement->events);
        self::assertSame('(wh1.C.def) -> (nil)', (string) $movement->events[0]->edge);
        self::assertSame(2, $movement->events[0]->quantity);
    }

    public function testMarkDefectiveMovesEligibleStockToDefect(): void
    {
        $example = new CommerceFlowExample();

        $result = $example->markDefective([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'C', 'inv' => ['ret' => 3]],
        ]);
        $movement = $this->movementResult($result);

        self::assertSame('(wh1.C.ret) -> (wh1.C.def)', (string) $movement->events[0]->edge);
        self::assertSame(2, $movement->events[0]->quantity);
    }

    public function testOutgressInventoryProjectsBatchMutationsToRepository(): void
    {
        $example = new CommerceFlowExample();
        $engineBatch = $example->prepareBatch([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'F', 'inv' => ['sd' => 3]],
        ]);
        (new BatchMovementEngine(new MovementEngine()))->execute(
            batch: $engineBatch,
            space: $this->space($example),
            cascade: $this->space($example)->getCascade('dispatch'),
        );

        $repo = new class implements Repository {
            /** @var list<array{variant: string, slot: string, delta: int}> */
            public array $applied = [];

            public function applyDelta(string $variant, Slot $slot, int $delta): void
            {
                $this->applied[] = [
                    'variant' => $variant,
                    'slot'    => $slot->key,
                    'delta'   => $delta,
                ];
            }
        };

        $example->outgressInventory($engineBatch, $repo);

        self::assertSame([
            ['variant' => 'A', 'slot' => 'wh1.F.sd', 'delta' => -2],
            ['variant' => 'A', 'slot' => 'wh1.F.dsp', 'delta' => 2],
        ], $repo->applied);
    }

    public function testOutgressLedgerBuildsLedgerRowsWithContext(): void
    {
        $example = new CommerceFlowExample();
        $space = $this->space($example);
        $batch = $example->prepareBatch([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'F', 'inv' => ['sd' => 3]],
        ]);

        (new BatchMovementEngine(new MovementEngine()))->execute(
            batch: $batch,
            space: $space,
            cascade: $space->getCascade('dispatch'),
        );

        $rows = $example->outgressLedger($batch, ['orderId' => 'SO-1', 'action' => 'dispatch']);

        self::assertCount(1, $rows);
        self::assertSame('A', $rows[0]['variant']);
        self::assertSame('(wh1.F.sd) -> (wh1.F.dsp)', (string) $rows[0]['entry']->edge);
        self::assertSame('wh1.F.sd', $rows[0]['entry']->edge->from->key);
        self::assertSame('wh1.F.dsp', $rows[0]['entry']->edge->to->key);
        self::assertSame(2, $rows[0]['entry']->quantity);
        self::assertSame(1, $rows[0]['entry']->finalFrom());
        self::assertSame(2, $rows[0]['entry']->finalTo());
        self::assertSame(['orderId' => 'SO-1', 'action' => 'dispatch'], $rows[0]['entry']->context);
    }

    private function space(CommerceFlowExample $example): SlotSpace
    {
        $reflection = new \ReflectionProperty($example, 'space');

        /** @var SlotSpace */
        return $reflection->getValue($example);
    }

    /**
     * @param array{result: \Nandan108\SlotFlow\MovementResult|null, subject: non-empty-string}[] $results
     */
    private function movementResult(array $results): \Nandan108\SlotFlow\MovementResult
    {
        $movement = $results[0]['result'] ?? null;

        self::assertNotNull($movement);

        return $movement;
    }
}
