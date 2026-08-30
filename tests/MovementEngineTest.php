<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Batch\BatchMovementEngine;
use Nandan108\SlotFlow\Batch\QuantityStateBatch;
use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\Policies\DimensionPriority;
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

        $after = $result->applyTo($inventory);

        self::assertSame(0, $result->remaining);
        self::assertCount(1, $result->events);
        self::assertSame(2, $inventory->get($fooFs), 'execute() must leave the caller state untouched');
        self::assertSame(5, $after->get($fooFs));
        self::assertSame(0, $after->get($nil));
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

        $after = $result->applyTo($inventory);

        self::assertSame(0, $result->remaining);
        self::assertCount(1, $result->events);
        self::assertSame(2, $inventory->get($fooFs), 'execute() must leave the caller state untouched');
        self::assertSame(0, $after->get($fooFs));
        self::assertSame(0, $after->get($nil));
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

        $after = $result->applyTo($inventory);

        self::assertSame(1, $result->remaining);
        self::assertCount(1, $result->events);
        self::assertSame(2, $inventory->get($space->slot('foo.fs')), 'execute() must leave the caller state untouched');
        self::assertSame(0, $after->get($space->slot('foo.fs')));
        self::assertSame(2, $after->get($space->slot('foo.sd')));
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

        $after = $result->applyTo($inventory);

        self::assertSame(0, $result->remaining);
        self::assertSame(6, $inventory->get('fs.main'), 'execute() must leave the caller state untouched');
        self::assertSame(2, $after->get('fs.main'));
        self::assertSame(4, $after->get('fs.annex'));
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

    /**
     * Execution is a pure computation over the state it is handed.
     *
     * The guarantee that makes a result safe to hold, compare or discard: running the same
     * movement twice against the same state produces the same answer, because the first run did
     * not consume anything. Before this held, a caller that executed speculatively and then threw
     * the result away was left with a silently half-moved state.
     */
    public function testExecuteDoesNotMutateTheCallerStateAndIsIdempotent(): void
    {
        $space = SlotSpace::define(['stt' => ['fs', 'res']])
            ->flow('reserve', static fn (Flow $flow) => $flow->move(['stt' => 'fs'], ['stt' => 'res']));

        $state = new QuantityState($space, [['fs', 10]]);
        $engine = new MovementEngine();

        $first = $engine->execute($state, $space, 'reserve', 4);
        $second = $engine->execute($state, $space, 'reserve', 4);

        self::assertSame(10, $state->get('fs'));
        self::assertSame(0, $state->get('res'));
        self::assertSame($first->remaining, $second->remaining);
        self::assertEquals(
            array_map(static fn ($d): array => [$d->slot->key, $d->delta], $first->deltas()),
            array_map(static fn ($d): array => [$d->slot->key, $d->delta], $second->deltas()),
        );
    }

    public function testApplyToReturnsTheResultingStateWithoutTouchingTheOriginal(): void
    {
        $space = SlotSpace::define(['stt' => ['fs', 'res']])
            ->flow('reserve', static fn (Flow $flow) => $flow->move(['stt' => 'fs'], ['stt' => 'res']));

        $state = new QuantityState($space, [['fs', 10]]);
        $result = (new MovementEngine())->execute($state, $space, 'reserve', 4);

        $after = $result->applyTo($state);

        self::assertNotSame($state, $after);
        self::assertSame(10, $state->get('fs'));
        self::assertSame(6, $after->get('fs'));
        self::assertSame(4, $after->get('res'));

        // Applying to the already-advanced state advances it again, so a result is a description
        // of a movement rather than a snapshot of one outcome.
        $twice = $result->applyTo($after);
        self::assertSame(2, $twice->get('fs'));
        self::assertSame(8, $twice->get('res'));
    }

    public function testWithDeltaIsTheImmutableSpellingOfAdd(): void
    {
        $space = SlotSpace::define(['stt' => ['fs']]);
        $state = new QuantityState($space, [['fs', 3]]);

        $updated = $state->withDelta($space->slot('fs'), -2);

        self::assertNotSame($state, $updated);
        self::assertSame(3, $state->get('fs'));
        self::assertSame(1, $updated->get('fs'));
    }

    /**
     * Quantities are int|float throughout, so completeness cannot be an identity test against
     * int 0: a satisfied float movement leaves 0.0, and `0 === 0.0` is false.
     */
    public function testIsCompleteHoldsForAFullySatisfiedFloatMovement(): void
    {
        $space = SlotSpace::define(['stt' => ['fs', 'res']])
            ->flow('reserve', static fn (Flow $flow) => $flow->move(['stt' => 'fs'], ['stt' => 'res']));

        $result = (new MovementEngine())->execute(
            new QuantityState($space, [['fs', 2.5]]),
            $space,
            'reserve',
            2.5,
        );

        self::assertSame(0.0, (float) $result->remaining);
        self::assertTrue($result->isComplete());
    }

    public function testDeltasPruneSlotsWhoseFloatMovementsNetToZero(): void
    {
        $space = SlotSpace::define(['stt' => ['a', 'b']])
            ->flow('round', static fn (Flow $flow) => $flow
                ->move(['stt' => 'a'], ['stt' => 'b'])
                ->move(['stt' => 'b'], ['stt' => 'a']));

        $result = (new MovementEngine())->execute(
            new QuantityState($space, [['a', 2.5]]),
            $space,
            'round',
            5.0,
        );

        // b receives 2.5 then gives it back, so it nets to zero and must not surface as a delta.
        self::assertCount(2, $result->events);
        self::assertSame([], array_map(static fn ($d): string => $d->slot->key, $result->deltas()));
    }

    /**
     * A batch item knows its subject and its results are labelled with it, so the policies that
     * decide its movement must see it too — otherwise a per-subject rule behaves as though no
     * subject were set, and only in batch mode, which is the mode such a rule exists for.
     */
    public function testBatchExecutionPassesEachItemSubjectToPolicies(): void
    {
        $space = SlotSpace::define(['stt' => ['fs', 'res']]);

        $collector = new class {
            /** @var list<string> */
            public array $seen = [];
        };

        $space->flow('reserve', static function (Flow $flow) use ($collector): void {
            $flow->move(['stt' => 'fs'], ['stt' => 'res'])
                ->constraint(static function (MovementEdge $edge, FlowContext $ctx) use ($collector): int | float {
                    $collector->seen[] = is_string($ctx->subject) ? $ctx->subject : get_debug_type($ctx->subject);

                    return \PHP_INT_MAX;
                });
        });

        $batch = QuantityStateBatch::fromRows(
            $space,
            [['sku' => 'SKU-A', 'qty' => 5], ['sku' => 'SKU-B', 'qty' => 5]],
            /** @param array{sku: string, qty: int} $row */
            static fn (array $row): string => $row['sku'],
            /** @param array{sku: string, qty: int} $row */
            static fn (array $row): array => [['fs', $row['qty']]],
            static fn (array $rows): int => 2,
        );

        (new BatchMovementEngine(new MovementEngine()))->execute($batch, $space, 'reserve');

        self::assertSame(['SKU-A', 'SKU-B'], $collector->seen);
    }

    /**
     * A step can be configured two ways, and they used to fight: `policies()` rebuilt every typed
     * bucket from its own bag, silently erasing whatever `orderBy()` had put there. The flow then
     * ran with no ordering and simply produced a different movement — supplier stock consumed
     * before warehouse stock, with nothing reported.
     */
    public function testPoliciesDoesNotDiscardPoliciesDeclaredThroughBuilderMethods(): void
    {
        $space = SlotSpace::define(['loc' => ['sup', 'wh1'], 'stt' => ['fs', 'res']])
            ->flow('reserve', static fn (Flow $flow) => $flow
                ->move(['stt' => 'fs'], ['stt' => 'res'])
                ->orderBy(new DimensionPriority(['loc' => ['wh*', 'sup']]))
                ->policies(new CapEachEdgeAtTwo()));

        $step = $space->getFlow('reserve')->steps()[0];
        self::assertCount(1, $step->orderingPolicies, 'orderBy() must survive a later policies() call');
        self::assertCount(1, $step->quantityConstraintPolicies);

        $result = (new MovementEngine())->execute(
            new QuantityState($space, [['wh1.fs', 5], ['sup.fs', 10]]),
            $space,
            'reserve',
            3,
        );

        // Warehouse before supplier: the declared ordering is still in force.
        self::assertSame('wh1.fs', $result->events[0]->edge->from->key);
        self::assertSame(2, $result->events[0]->quantity);
        self::assertSame('sup.fs', $result->events[1]->edge->from->key);
    }

    /**
     * Buckets are rebuilt from the policy bag on every `policies()` call, so a second call must
     * re-derive the first call's entries rather than stack another copy on top of them.
     *
     * Adding the *same* unnamed policy twice does legitimately yield two entries — deduplication is
     * what `NamedPolicy` is for — so this uses two distinct policies to isolate the question.
     */
    public function testRepeatedPoliciesCallsRederiveRatherThanDuplicate(): void
    {
        $first = new CapEachEdgeAtTwo();
        $second = new CapEachEdgeAtTwo();

        $space = SlotSpace::define(['stt' => ['fs', 'res']])
            ->flow('reserve', static fn (Flow $flow) => $flow
                ->move(['stt' => 'fs'], ['stt' => 'res'])
                ->policies($first)
                ->policies($second));

        $step = $space->getFlow('reserve')->steps()[0];

        self::assertSame([$first, $second], $step->quantityConstraintPolicies);
    }
}

/**
 * Caps every edge at two units, so a movement has to fall through to the next edge in order.
 */
final class CapEachEdgeAtTwo implements QttyConstraintPolicyInterface
{
    #[\Override]
    public function constraint(MovementEdge $edge, FlowContext $ctx): int | float
    {
        return 2;
    }
}
