<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\Policies\DimensionPriority;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\Rules\EdgeRuleBase;
use Nandan108\SlotFlow\Runtime\FlowContext;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Solvers\GreedyFlowSolver;
use PHPUnit\Framework\TestCase;

/**
 * The decision trace answers "why did it move that", and more often "why did it move nothing".
 *
 * Every defect this library has had in this area was a silent one — a discarded ordering policy, a
 * dropped subject, a movement along an edge nobody declared. None of them raised anything; they
 * just produced a different movement. A trace is what makes that class of problem visible.
 */
final class MovementTraceTest extends TestCase
{
    public function testTracingIsOffByDefault(): void
    {
        $space = self::space();
        $result = (new MovementEngine())->execute(
            new QuantityState($space, [['wh1.fs', 2]]),
            $space,
            'reserve',
            1,
        );

        self::assertNull($result->trace());
    }

    /**
     * The three quantities separate the causes that otherwise look identical from the outside.
     */
    public function testATraceDistinguishesAnEmptySourceFromAConstraintThatCappedIt(): void
    {
        $space = self::space(new RefuseSupplier());

        $result = self::tracingEngine()->execute(
            new QuantityState($space, [['wh1.fs', 2], ['sup.fs', 10]]),
            $space,
            'reserve',
            6,
        );

        $trace = $result->trace();
        self::assertNotNull($trace);
        self::assertCount(1, $trace);
        self::assertArrayHasKey(0, $trace);

        $step = $trace[0];
        self::assertSame(0, $step['step']);
        self::assertSame(6, $step['remainingBefore']);
        self::assertSame(4, $step['remainingAfter']);
        self::assertSame(2, $step['applied']);
        self::assertFalse($step['byAllocation']);

        // The declared ordering is visible as an ordering, not merely in its effect.
        self::assertSame(
            ['(wh1.fs) -> (wh1.res)', '(sup.fs) -> (sup.res)'],
            $step['afterOrdering'],
        );

        $warehouse = $step['edges'][0];
        $supplier = $step['edges'][1];

        self::assertSame(2, $warehouse['available']);
        self::assertSame(2, $warehouse['moved']);

        // The supplier edge had stock; a policy is what stopped it. Were `available` also zero,
        // the answer would have been "nothing there to move" instead.
        self::assertSame(4, $supplier['available']);
        self::assertSame(0, $supplier['movable']);
        self::assertSame(0, $supplier['moved']);
    }

    public function testATraceShowsWhenTheTopologyRefusedTheMovementOutright(): void
    {
        $space = SlotSpace::define(['loc' => ['wh1'], 'stt' => ['fs', 'res', 'sd']]);
        $space->edgeRules([EdgeRule::allowLabeled('reserve', 'wh1.fs', 'wh1.res')], EdgeRuleBase::None);
        $space->flow('illegal', static fn (Flow $flow) => $flow->move(['stt' => 'fs'], ['stt' => 'sd']));

        $result = self::tracingEngine()->execute(
            new QuantityState($space, [['wh1.fs', 5]]),
            $space,
            'illegal',
            5,
        );

        $trace = $result->trace();
        self::assertNotNull($trace);
        self::assertArrayHasKey(0, $trace);

        // No candidate at all is a different story from a candidate that moved nothing: the pair
        // was never a declared edge, so the step had nothing to consider.
        self::assertSame([], $trace[0]['candidates']);
        self::assertSame([], $trace[0]['edges']);
        self::assertSame(0, $trace[0]['applied']);
        self::assertSame(5, $result->remaining);
    }

    public function testATraceRecordsFilteringAndOrderingSeparately(): void
    {
        $space = SlotSpace::define(['loc' => ['sup', 'wh1'], 'stt' => ['fs', 'res']])
            ->flow('reserve', static fn (Flow $flow) => $flow
                ->move(['stt' => 'fs'], ['stt' => 'res'])
                ->filter(static fn (FlowContext $ctx): array => array_values(array_filter(
                    $ctx->edges,
                    static fn (MovementEdge $edge): bool => 'sup' !== $edge->from['loc'],
                )))
                ->orderBy(new DimensionPriority(['loc' => ['wh*', 'sup']])));

        $result = self::tracingEngine()->execute(
            new QuantityState($space, [['wh1.fs', 5], ['sup.fs', 5]]),
            $space,
            'reserve',
            3,
        );

        $trace = $result->trace();
        self::assertNotNull($trace);
        $step = $trace[0];

        self::assertCount(2, $step['candidates']);
        self::assertSame(['(wh1.fs) -> (wh1.res)'], $step['afterFilters']);
        self::assertSame(['(wh1.fs) -> (wh1.res)'], $step['afterOrdering']);
    }

    private static function tracingEngine(): MovementEngine
    {
        return new MovementEngine(new GreedyFlowSolver(trace: true));
    }

    private static function space(?QttyConstraintPolicyInterface $constraint = null): SlotSpace
    {
        $space = SlotSpace::define(['loc' => ['sup', 'wh1'], 'stt' => ['fs', 'res']]);

        return $space->flow('reserve', static function (Flow $flow) use ($constraint): void {
            $step = $flow->move(['stt' => 'fs'], ['stt' => 'res'])
                ->orderBy(new DimensionPriority(['loc' => ['wh*', 'sup']]));

            if (null !== $constraint) {
                $step->constraint($constraint);
            }
        });
    }
}

/**
 * Refuses supplier-side edges outright, so they appear in a trace with stock available but nothing
 * movable.
 */
final class RefuseSupplier implements QttyConstraintPolicyInterface
{
    #[\Override]
    public function constraint(MovementEdge $edge, FlowContext $ctx): int | float
    {
        return 'sup' === $edge->from['loc'] ? 0 : \PHP_INT_MAX;
    }
}
