<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Cascade;
use Nandan108\SlotFlow\Inventory;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\CascadeContext;
use Nandan108\SlotFlow\SlotSpace;
use PHPUnit\Framework\TestCase;

final class MovementEngineTest extends TestCase
{
    public function testItTreatsNilSourceAsAnUnboundedInput(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo'],
            'stt'   => ['fs'],
        ]);

        $fooFs = $space->slot('foo.fs');
        $nil = $space->nilSlot();
        $inventory = new Inventory($space, [[$fooFs, 2]]);
        $cascade = Cascade::define('inbound', static fn (Cascade $cascade) => $cascade
            ->move(null, 'foo.fs'));

        $result = (new MovementEngine())->execute(
            $inventory,
            $space,
            $cascade,
            3,
        );

        self::assertSame(0, $result->remaining);
        self::assertCount(1, $result->events);
        self::assertSame(5, $inventory->get($fooFs));
        self::assertSame(0, $inventory->get($nil));
        self::assertNull($result->events[0]->initialFrom);
    }

    public function testItTreatsNilSinkAsAnOpenEndedOutput(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo'],
            'stt'   => ['fs'],
        ]);

        $fooFs = $space->slot('foo.fs');
        $nil = $space->nilSlot();
        $inventory = new Inventory($space, [[$fooFs, 2]]);
        $cascade = Cascade::define('outbound', static fn (Cascade $cascade) => $cascade
            ->move('foo.fs', null));

        $result = (new MovementEngine())->execute(
            $inventory,
            $space,
            $cascade,
            2,
        );

        self::assertSame(0, $result->remaining);
        self::assertCount(1, $result->events);
        self::assertSame(0, $inventory->get($fooFs));
        self::assertSame(0, $inventory->get($nil));
        self::assertNull($result->events[0]->initialTo);
    }

    public function testItExecutesCascadeWithTheSameGreedySemantics(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo'],
            'stt'   => ['fs', 'sd'],
        ]);

        $inventory = new Inventory($space, [[$space->slot('foo.fs'), 2]]);
        $cascade = Cascade::define('sell', static fn (Cascade $cascade) => $cascade
            ->move('foo.fs', 'foo.sd'));

        $result = (new MovementEngine())->execute($inventory, $space, $cascade, 3);

        self::assertSame(1, $result->remaining);
        self::assertCount(1, $result->events);
        self::assertSame(0, $inventory->get($space->slot('foo.fs')));
        self::assertSame(2, $inventory->get($space->slot('foo.sd')));
    }

    public function testItExecutesCascadeAllocationPolicies(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['a', 'b', 'dest'],
            'stt'   => ['fs', 'sd'],
        ]);

        $inventory = new Inventory($space, [
            [$space->slot('a.fs'), 4],
            [$space->slot('b.fs'), 4],
        ]);

        $cascade = Cascade::define('allocate', static fn (Cascade $cascade) => $cascade
            ->move('a|b.fs', 'dest.sd')
            ->allocate(static function (CascadeContext $context): array {
                $edges = $context->edges;

                return [
                    new AllocationDecision($edges[1], min(2, $context->quantity)),
                    new AllocationDecision($edges[0], $context->quantity),
                ];
            }));

        $result = (new MovementEngine())->execute($inventory, $space, $cascade, 3);

        self::assertSame(0, $result->remaining);
        self::assertCount(2, $result->events);
        self::assertSame('b.fs', $result->events[0]->edge->from->key);
        self::assertSame(2, $result->events[0]->quantity);
        self::assertSame('a.fs', $result->events[1]->edge->from->key);
        self::assertSame(1, $result->events[1]->quantity);
    }

    public function testCascadeCanReverseConditionallyAndFlipEdges(): void
    {
        $cascade = Cascade::define('reverse', static fn (Cascade $cascade) => $cascade
            ->move('foo.fs', 'foo.sd')
            ->move('bar.fs', 'bar.sd'));

        $reversed = $cascade->reverseIf(condition: true, flipEdges: true);

        self::assertNotSame($cascade, $reversed);
        self::assertSame('foo.fs', $cascade->steps()[0]->from);
        self::assertSame('bar.sd', $reversed->steps()[0]->from);
        self::assertSame('bar.fs', $reversed->steps()[0]->to);
        self::assertSame('foo.sd', $reversed->steps()[1]->from);
        self::assertSame('foo.fs', $reversed->steps()[1]->to);
    }

    public function testItCanExecuteStepsResolvedFromLabeledEdges(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo', 'dest'],
            'stt'   => ['fs', 'sd'],
        ])->edgeRules([
            \Nandan108\SlotFlow\Rules\EdgeRule::allowLabeled('sell', 'foo.fs', 'dest.sd'),
        ]);

        $inventory = new Inventory($space, [[$space->slot('foo.fs'), 2]]);
        $cascade = Cascade::define('sell', static fn (Cascade $c) => $c->stepByLabeledEdges('sell'));

        $result = (new MovementEngine())->execute($inventory, $space, $cascade, 2);

        self::assertSame(0, $result->remaining);
        self::assertCount(1, $result->events);
        self::assertSame('(foo.fs) -> (dest.sd)', (string) $result->events[0]->edge);
    }

    public function testItCanSubstituteCascadeParametersBeforePatternExpansion(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['sup', 'wh1'],
            'own'   => ['C'],
            'state' => ['sd', 'fs'],
        ]);

        $inventory = new Inventory($space, [
            [$space->slot('sup.C.sd'), 2],
        ]);
        $cascade = Cascade::define('receive', static fn (Cascade $cascade) => $cascade
            ->move('sup.{own}.{state}', '{loc}.{own}.{state}')
            ->move(null, '{loc}.{own}.fs'));

        $result = (new MovementEngine())->execute(
            $inventory,
            $space,
            $cascade,
            3,
            appContext: ['params' => [
                'loc'   => 'wh1',
                'own'   => 'C',
                'state' => 'sd',
            ]],
        );

        self::assertSame(0, $result->remaining);
        self::assertCount(2, $result->events);
        self::assertSame('(sup.C.sd) -> (wh1.C.sd)', (string) $result->events[0]->edge);
        self::assertSame('(nil) -> (wh1.C.fs)', (string) $result->events[1]->edge);
    }
}
