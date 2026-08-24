<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\FlowContext;
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
        $inventory = new QuantityState($space, [[$fooFs, 2]]);
        $cascade = Flow::define('inbound', static fn (Flow $cascade) => $cascade
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
        $inventory = new QuantityState($space, [[$fooFs, 2]]);
        $cascade = Flow::define('outbound', static fn (Flow $cascade) => $cascade
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

        $inventory = new QuantityState($space, [['foo.fs', 2]]);
        $cascade = Flow::define('sell', static fn (Flow $cascade) => $cascade
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

        $inventory = new QuantityState($space, [
            ['a.fs', 4],
            ['b.fs', 4],
        ]);

        $cascade = Flow::define('allocate', static fn (Flow $cascade) => $cascade
            ->move('a|b.fs', 'dest.sd')
            ->allocate(static function (FlowContext $context): array {
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

    public function testFlowCanReverseConditionallyAndFlipEdges(): void
    {
        $cascade = Flow::define('reverse', static fn (Flow $cascade) => $cascade
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

        $inventory = new QuantityState($space, [['foo.fs', 2]]);
        $cascade = Flow::define('sell', static fn (Flow $c) => $c->stepByLabeledEdges('sell'));

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

        $inventory = new QuantityState($space, [
            ['sup.C.sd', 2],
        ]);
        $cascade = Flow::define('receive', static fn (Flow $cascade) => $cascade
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

    /**
     * One definition, both endpoints parameterized — the shape a two-location transfer needs and
     * the reason a pattern may not be fully known when the flow is written. `from` and `to` are
     * different values of the same dimension, which no single compile-time pattern can express.
     */
    public function testItResolvesBothEndpointsOfAParameterizedMove(): void
    {
        $space = SlotSpace::define([
            'stt' => ['fs'],
            'loc' => ['main', 'annex'],
        ]);

        $inventory = new QuantityState($space, [['fs.main', 6]]);
        $transfer = Flow::define('transfer', static fn (Flow $flow) => $flow
            ->move(['loc' => '{from}'], ['loc' => '{to}']));

        $result = (new MovementEngine())->execute(
            $inventory,
            $space,
            $transfer,
            4,
            params: ['from' => 'main', 'to' => 'annex'],
        );

        self::assertSame(0, $result->remaining);
        self::assertSame(2, $inventory->get('fs.main'));
        self::assertSame(4, $inventory->get('fs.annex'));
    }

    /**
     * A parameter the caller never passed is a mistake at the call site, so the message must point
     * there. Left to travel on, the literal `{to}` reaches the codec and is reported as an invalid
     * *dimension value* — which reads as a schema fault and hides the real one.
     */
    public function testItNamesTheMissingParameterRatherThanBlamingTheDimension(): void
    {
        $space = SlotSpace::define([
            'stt' => ['fs'],
            'loc' => ['main', 'annex'],
        ]);

        $transfer = Flow::define('transfer', static fn (Flow $flow) => $flow
            ->move(['loc' => '{from}'], ['loc' => '{to}']));

        // Params supplied, but one name misspelled — the likeliest version of this mistake.
        try {
            (new MovementEngine())->execute(
                new QuantityState($space, [['fs.main', 6]]),
                $space,
                $transfer,
                4,
                params: ['from' => 'main', 'too' => 'annex'],
            );
            self::fail('Expected the unresolved parameter to be refused.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame(
                'Slot pattern for dimension \'loc\' needs parameter "to", which was not supplied — given: from, too.',
                $e->getMessage(),
            );
        }

        // No params at all — same refusal, and it says so rather than listing an empty set.
        try {
            (new MovementEngine())->execute(
                new QuantityState($space, [['fs.main', 6]]),
                $space,
                $transfer,
                4,
            );
            self::fail('Expected the unresolved parameter to be refused.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame(
                'Slot pattern for dimension \'loc\' needs parameter "from", which was not supplied (no execute params were given).',
                $e->getMessage(),
            );
        }
    }
}
