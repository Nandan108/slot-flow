<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Codecs\DefaultSlotKeyCodec;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Time\TimeAxis;
use Nandan108\SlotFlow\Time\TimedMovementEdge;
use Nandan108\SlotFlow\Time\TimedQuantityState;
use Nandan108\SlotFlow\Time\TimedSlotSpace;
use PHPUnit\Framework\TestCase;

final class TimeAwareTestCodec extends DefaultSlotKeyCodec
{
}

final class TimeLayerTest extends TestCase
{
    public function testTimeAxisParsesAndNormalizesCompositeTimeExpressions(): void
    {
        $axis = TimeAxis::define(
            bucket: 'hour',
            horizon: 200,
            aliases: ['shift' => 8, 'day' => 24],
        );

        self::assertSame('h0', $axis->key(0));
        self::assertSame(0, $axis->parse(0));
        self::assertSame(27, $axis->parse('d1h3'));
        self::assertSame(80, $axis->parse('d3s1'));
        self::assertSame('h80', $axis->normalize('d3s1'));
        self::assertSame('hour', $axis->bucket);
        self::assertSame(['shift' => 8, 'day' => 24], $axis->aliases);
        self::assertTrue($axis->contains('d3s1'));
        self::assertFalse($axis->contains('d9'));
    }

    public function testTimeAxisRejectsUnknownUnitsNegativeValuesAndDuplicateShorthands(): void
    {
        $axis = TimeAxis::define(bucket: 'hour', horizon: 24, aliases: ['day' => 24]);

        try {
            $axis->parse('w1');
            self::fail('Expected unknown time unit rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Unknown time unit in expression.', $e->getMessage());
        }

        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage('Time values must be zero or greater.');
        $axis->parse(-1);
    }

    public function testTimeAxisRejectsDuplicateFirstLettersAcrossBucketAndAliases(): void
    {
        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage('Time bucket and aliases must have unique first letters.');

        TimeAxis::define('day', 10, ['dock' => 2]);
    }

    public function testSlotSpaceCanStoreTimeAxisAndPassItToTheCodec(): void
    {
        $timeAxis = new TimeAxis('hour', 24, ['shift' => 8, 'day' => 24]);
        $space = SlotSpace::define(
            dimensions: [
                'loc' => ['sup', 'plant'],
                'stt' => ['raw', 'wip'],
            ],
            timeAxis: $timeAxis,
            codecClass: TimeAwareTestCodec::class,
        );

        self::assertSame($timeAxis, $space->timeAxis);
        self::assertInstanceOf(TimeAwareTestCodec::class, $space->codec);
        self::assertSame($timeAxis, $space->codec->timeAxis);
    }

    public function testTimedSlotSpaceResolvesTimedSlotsByTupleOrSerializedKey(): void
    {
        $space = SlotSpace::define(
            dimensions: [
                'loc' => ['sup', 'plant'],
                'stt' => ['raw', 'wip', 'fg'],
            ],
            timeAxis: TimeAxis::define(bucket: 'hour', horizon: 10, aliases: ['day' => 24]),
        );
        $timed = TimedSlotSpace::fromBaseSpace($space);

        $slot = $timed->slot('sup.raw', 'h3');

        self::assertSame($space->timeAxis, $timed->axis);
        self::assertSame('sup.raw@h3', $slot->key);
        self::assertSame('h3', $slot->timeKey);
        self::assertSame(3, $slot->timeIndex);
        self::assertSame('sup', $slot->dimension('loc'));
        self::assertTrue($slot->equals($timed->slot('sup.raw@h3')));
        self::assertSame('sup.raw@h5', $slot->at(5)->key);
    }

    public function testTimedSlotSpaceRequiresAnAxisWhenTheBaseSpaceHasNone(): void
    {
        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Timed slot space requires a TimeAxis, either passed explicitly or declared on the base SlotSpace.',
        );

        TimedSlotSpace::fromBaseSpace($this->makeTimedBaseSpace());
    }

    public function testTimedSlotSpaceAddsHoldoverAndDurationExpandedEdges(): void
    {
        $space = $this->makeTimedBaseSpace()->edgeRules([
            EdgeRule::allowLabeled('ship', 'sup.raw', 'plant.raw', ['duration' => 'd2', 'lane' => 'truck']),
            EdgeRule::allowLabeled('process', 'plant.raw', 'plant.wip', ['duration' => 8]),
            EdgeRule::allowLabeled('finish', 'plant.wip', 'plant.fg', ['duration' => 'd1']),
        ]);

        $timed = TimedSlotSpace::fromBaseSpace(
            $space,
            TimeAxis::define(bucket: 'hour', horizon: 72, aliases: ['day' => 24]),
        );

        $origin = $timed->slot('sup.raw', 0);
        $edges = $timed->getEdgesFrom($origin);

        self::assertSame(
            ['sup.raw@h1', 'plant.raw@h48'],
            array_map(static fn (TimedMovementEdge $edge): string => $edge->to->key, $edges),
        );
        self::assertSame('hold', $edges[0]->label);
        self::assertSame(['duration' => 1, 'timed-kind' => 'holdover'], $edges[0]->attributes);
        self::assertSame('ship', $edges[1]->label);
        self::assertSame(48, $edges[1]->attributes['duration']);
        self::assertSame('movement', $edges[1]->attributes['timed-kind']);
        self::assertSame('truck', $edges[1]->attributes['lane']);
    }

    public function testTimedSlotSpaceSkipsEdgesThatArriveBeyondTheHorizon(): void
    {
        $space = $this->makeTimedBaseSpace()->edgeRules([
            EdgeRule::allowLabeled('finish', 'plant.wip', 'plant.fg', ['duration' => 5]),
        ]);

        $timed = TimedSlotSpace::fromBaseSpace($space, TimeAxis::define('tick', 4));

        self::assertSame(
            ['plant.wip@t4'],
            array_map(
                static fn (TimedMovementEdge $edge): string => $edge->to->key,
                $timed->getEdgesFrom($timed->slot('plant.wip', 3)),
            ),
        );
    }

    public function testTimedQuantityStateCanBeExpandedSplitAndMerged(): void
    {
        $space = $this->makeTimedBaseSpace();
        $timedSpace = TimedSlotSpace::fromBaseSpace($space, TimeAxis::define('tick', 12));
        $baseState = new QuantityState($space, [
            ['sup.raw', 10],
            ['plant.fg', 2],
        ]);

        $timedState = TimedQuantityState::fromQuantityState($timedSpace, $baseState, 0);

        self::assertSame(10, $timedState->get('sup.raw@t0'));
        self::assertSame(2, $timedState->get('plant.fg@t0'));

        $timedState->add($timedSpace->slot('sup.raw', 0), -4);
        $timedState->add($timedSpace->slot('sup.raw', 1), 4);
        $timedState->add($timedSpace->slot('plant.fg', 1), 2);
        $timedState->add($timedSpace->slot('plant.fg', 1), 3);

        self::assertSame(6, $timedState->get('sup.raw@t0'));
        self::assertSame(4, $timedState->get('sup.raw@t1'));
        self::assertSame(5, $timedState->get('plant.fg@t1'));

        $copy = $timedState->copy();
        $copy->add($timedSpace->slot('plant.fg', 1), 2);

        self::assertSame(5, $timedState->get('plant.fg@t1'));
        self::assertSame(7, $copy->get('plant.fg@t1'));
    }

    public function testTimedQuantityStateAcceptsSerializedTimedTuples(): void
    {
        $space = $this->makeTimedBaseSpace();
        $timedSpace = TimedSlotSpace::fromBaseSpace($space, TimeAxis::define('tick', 6));
        $timedState = new TimedQuantityState($timedSpace, [
            ['sup.raw@t1', 3],
            ['plant.wip', 2, 4],
        ]);

        self::assertSame(
            ['sup.raw@t1' => 3, 'plant.wip@t4' => 2],
            $timedState->all(),
        );
    }

    private function makeTimedBaseSpace(): SlotSpace
    {
        return SlotSpace::define([
            'loc' => ['sup', 'plant'],
            'stt' => ['raw', 'wip', 'fg'],
        ]);
    }
}
