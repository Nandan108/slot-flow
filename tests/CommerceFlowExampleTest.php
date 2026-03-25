<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\SlotSpace;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\CommerceFlowExample;

final class CommerceFlowExampleTest extends TestCase
{
    public function testPrepareBatchGroupsRowsByVariant(): void
    {
        $example = new CommerceFlowExample();

        $batch = $example->prepareBatch([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'CS', 'inv' => ['fs' => 5, 'sd' => 1], 'ifs' => 0],
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'sup', 'own' => 'CS', 'inv' => ['fs' => 3, 'sd' => 2], 'ifs' => 0],
            ['var' => 'B', 'mvQtty' => 1, 'loc' => 'wh2', 'own' => 'FP', 'inv' => ['fs' => 4, 'sd' => 0], 'ifs' => 0],
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
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'CS', 'inv' => ['fs' => 1, 'sd' => 3]],
        ];

        $space->cascade('ingress', [
            ['*.*.sd', '*.*.fs'],
        ]);

        $result = $example->processBatch($rows, $space->getCascade('ingress'));
        $movement = $this->movementResult($result);

        self::assertSame(0, $movement->remaining);
        self::assertSame('(wh1.CS.sd) -> (wh1.CS.fs)', (string) $movement->events[0]->edge);
        self::assertSame(2, $movement->events[0]->quantity);
    }

    public function testMoveReceivePOForConsignmentMovesSoldThenOverflow(): void
    {
        $example = new CommerceFlowExample();

        $result = $example->receivePO([
            ['var' => 'A', 'mvQtty' => 5, 'loc' => 'sup', 'own' => 'CS', 'inv' => ['fs' => 0, 'sd' => 3]],
            ['var' => 'A', 'mvQtty' => 5, 'loc' => 'wh1', 'own' => 'CS', 'inv' => ['fs' => 10, 'sd' => 1]],
        ], 'CS', 'wh1', false);
        $movement = $this->movementResult($result);

        self::assertSame(0, $movement->remaining);
        $events = $movement->events;
        self::assertCount(2, $events);
        self::assertSame('(sup.CS.sd) -> (wh1.CS.sd)', (string) $events[0]->edge);
        self::assertSame(3, $events[0]->quantity);
        self::assertSame('(nil) -> (wh1.CS.fs)', (string) $events[1]->edge);
        self::assertSame(2, $events[1]->quantity);
    }

    public function testMoveReceivePOReverseMovesWarehouseOverflowOutFirst(): void
    {
        $example = new CommerceFlowExample();

        $result = $example->receivePO([
            ['var' => 'A', 'mvQtty' => 4, 'loc' => 'sup', 'own' => 'CS', 'inv' => ['fs' => 0, 'sd' => 0]],
            ['var' => 'A', 'mvQtty' => 4, 'loc' => 'wh1', 'own' => 'CS', 'inv' => ['fs' => 3, 'sd' => 2]],
        ], 'CS', 'wh1', true);
        $movement = $this->movementResult($result);
        $events = $movement->events;

        self::assertSame(0, $movement->remaining);
        self::assertCount(2, $events);
        self::assertSame('(wh1.CS.fs) -> (nil)', (string) $events[0]->edge);
        self::assertSame(3, $events[0]->quantity);
        self::assertSame('(wh1.CS.sd) -> (sup.CS.sd)', (string) $events[1]->edge);
        self::assertSame(1, $events[1]->quantity);
    }

    public function testIngressMovesSoldBackToForsale(): void
    {
        $example = new CommerceFlowExample();
        $space = $this->space($example);

        $space->cascade('ingress', [
            ['*.*.sd', '*.*.fs'],
        ]);

        $result = $example->processBatch([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'CS', 'inv' => ['fs' => 1, 'sd' => 3]],
        ], 'ingress');
        $movement = $this->movementResult($result);

        self::assertSame(0, $movement->remaining);
        $events = $movement->events;
        self::assertCount(1, $events);
        self::assertSame('(wh1.CS.sd) -> (wh1.CS.fs)', (string) $events[0]->edge);
        self::assertSame(2, $events[0]->quantity);
        self::assertSame(3, $events[0]->initialFrom);
    }

    public function testReserveMovesForsaleToCart(): void
    {
        $example = new CommerceFlowExample();

        $result = $example->reserve([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'CS', 'inv' => ['fs' => 5, 'res' => 1]],
        ]);
        $movement = $this->movementResult($result);

        self::assertSame(0, $movement->remaining);
        self::assertCount(1, $movement->events);
        self::assertSame('(wh1.CS.fs) -> (wh1.CS.res)', (string) $movement->events[0]->edge);
        self::assertSame(2, $movement->events[0]->quantity);
    }

    public function testReleaseMovesCartBackToForsale(): void
    {
        $example = new CommerceFlowExample();

        $result = $example->release([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'CS', 'inv' => ['res' => 5, 'fs' => 1]],
        ]);
        $movement = $this->movementResult($result);

        self::assertSame('(wh1.CS.res) -> (wh1.CS.fs)', (string) $movement->events[0]->edge);
        self::assertSame(2, $movement->events[0]->quantity);
    }

    public function testSellMovesCartToSold(): void
    {
        $example = new CommerceFlowExample();

        $result = $example->book([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'CS', 'inv' => ['res' => 5, 'sd' => 1]],
        ]);
        $movement = $this->movementResult($result);

        self::assertSame('(wh1.CS.res) -> (wh1.CS.sd)', (string) $movement->events[0]->edge);
        self::assertSame(2, $movement->events[0]->quantity);
    }

    public function testDiscardDefectiveMovesStockToNil(): void
    {
        $example = new CommerceFlowExample();

        $result = $example->discardDefective([
            ['var' => 'A', 'mvQtty' => 2, 'loc' => 'wh1', 'own' => 'CS', 'inv' => ['def' => 3]],
        ]);
        $movement = $this->movementResult($result);

        self::assertSame(0, $movement->remaining);
        self::assertCount(1, $movement->events);
        self::assertSame('(wh1.CS.def) -> (nil)', (string) $movement->events[0]->edge);
        self::assertSame(2, $movement->events[0]->quantity);
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
