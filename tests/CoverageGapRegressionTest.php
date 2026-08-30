<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\Internal\FlowStep;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\PlanRequest;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\FlowContext;
use Nandan108\SlotFlow\ScheduleRequest;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Solvers\AbstractPathSolver;
use Nandan108\SlotFlow\Solvers\EarliestArrivalSolver;
use Nandan108\SlotFlow\Solvers\GreedyFlowSolver;
use Nandan108\SlotFlow\Solvers\GreedyPlanSolver;
use Nandan108\SlotFlow\Time\TimeAxis;
use Nandan108\SlotFlow\Time\WeeklyCalendar;
use Nandan108\SlotFlow\Time\WeeklyCalendarMoment;
use PHPUnit\Framework\TestCase;

final class CoveragePathSolverProbe extends AbstractPathSolver
{
    /**
     * @param array<string, string>                      $params
     * @param string|array<int|string, string|null>|null $pattern
     */
    public function resolvePattern(string | array | null $pattern, array $params): string | array | null
    {
        return $this->resolvePatternParameters($pattern, $params);
    }

    /**
     * @param array<string, string> $params
     *
     * @return list<MovementEdge>
     */
    public function resolveEdges(SlotSpace $space, FlowStep $step, array $params): array
    {
        return $this->resolveOneStepEdges($space, $step, $params);
    }

    /**
     * @param list<MovementEdge>    $edges
     * @param array<string, string> $params
     *
     * @return array<string, array{edge: MovementEdge, quantity: int|float}>
     */
    public function available(FlowStep $step, array $edges, QuantityState $inventory, int | float $quantity, array $params): array
    {
        return $this->availableEdgesForStep($step, $edges, $inventory, $quantity, $params);
    }
}

final class CoverageGapRegressionTest extends TestCase
{
    public function testPathSolverHelpersCoverArrayPatternResolutionAndSkippedPolicies(): void
    {
        $space = SlotSpace::define([
            'loc' => ['src', 'dest'],
            'stt' => ['fs'],
        ]);
        $inventory = new QuantityState($space, [['src.fs', 2]]);
        $probe = new CoveragePathSolverProbe();

        /** @psalm-suppress InvalidArgument */
        $step = new FlowStep(
            from: ['loc' => '{from}', 'stt' => 'fs'],
            to: ['loc' => 'dest', 'stt' => 'fs'],
            orderingPolicies: [new \stdClass(), static fn (FlowContext $context): array => $context->edges],
            filterPolicies: [new \stdClass(), static fn (FlowContext $context): array => $context->edges],
            quantityConstraintPolicies: [new \stdClass(), static fn (MovementEdge $edge, FlowContext $context): string => 'skip'],
            allocationPolicies: [
                new \stdClass(),
                static fn (FlowContext $context): array => [new AllocationDecision($context->edges[0], $context->quantity)],
            ],
        );

        self::assertSame(
            ['loc' => 'src', 'stt' => 'fs', 'meta' => null],
            $probe->resolvePattern(['loc' => '{from}', 'stt' => 'fs', 'meta' => null], ['from' => 'src']),
        );

        $resolvedEdges = $probe->resolveEdges($space, $step, ['from' => 'src']);
        self::assertCount(1, $resolvedEdges);
        self::assertSame('src.fs', $resolvedEdges[0]->from->key);
        self::assertSame('dest.fs', $resolvedEdges[0]->to->key);

        $available = $probe->available($step, $resolvedEdges, $inventory, 2, ['from' => 'src']);

        self::assertCount(1, $available);
        self::assertSame(2, array_values($available)[0]['quantity']);
    }

    public function testGreedyPlanSolverBacktracksAcrossMismatchedIntermediateAndFinalEdges(): void
    {
        $space = SlotSpace::define([
            'loc' => ['src', 'mid1', 'mid2', 'dest', 'overflow'],
            'stt' => ['fs'],
        ])->edgeRules([
            EdgeRule::allowLabeled('collect', 'src.fs', 'mid1.fs'),
            EdgeRule::allowLabeled('collect', 'src.fs', 'mid2.fs'),
            EdgeRule::allowLabeled('deliver', 'mid2.fs', 'dest.fs'),
            EdgeRule::allowLabeled('deliver', 'mid1.fs', 'overflow.fs'),
        ]);

        $flow = Flow::define('ship', static fn (Flow $flow) => $flow
            ->stepByLabeledEdges('collect')
            ->stepByLabeledEdges('deliver'));

        $plan = (new GreedyPlanSolver())->plan(new PlanRequest(
            state: new QuantityState($space, [['src.fs', 1]]),
            space: $space,
            flow: $flow,
            quantity: 1,
            target: 'dest.fs',
        ));

        self::assertTrue($plan->isComplete());
        self::assertCount(2, $plan->steps);
        self::assertSame('mid2.fs', $plan->steps[0]->edge->to->key);
        self::assertSame('mid2.fs', $plan->steps[1]->edge->from->key);
    }

    public function testGreedyFlowSolverCoversSlotPatternsAndEmptyResolvedParameters(): void
    {
        $space = SlotSpace::define([
            'loc' => ['src', 'dest'],
            'stt' => ['fs'],
        ])->edgeRules([
            EdgeRule::allow('src.fs', 'dest.fs'),
        ]);

        $solver = new GreedyFlowSolver();
        $resolvePattern = new \ReflectionMethod($solver, 'resolvePatternParameters');

        self::assertSame('src.fs', $resolvePattern->invoke($solver, $space->slot('src.fs'), []));

        try {
            $resolvePattern->invoke($solver, '', []);
            self::fail('Expected empty resolved string pattern rejection.');
        } catch (\Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException $e) {
            self::assertSame('Resolved slot pattern cannot be empty string.', $e->getMessage());
        }

        try {
            $resolvePattern->invoke($solver, ['loc' => '', 'stt' => 'fs'], []);
            self::fail('Expected empty resolved array value rejection.');
        } catch (\Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException $e) {
            self::assertSame("Resolved slot pattern value for dimension 'loc' cannot be empty string.", $e->getMessage());
        }
    }

    public function testTimeAxisAndWeeklyHelpersCoverRemainingEnumeratedBranches(): void
    {
        self::assertSame(1, TimeAxis::define('second', 1)->secondsInBucket);
        self::assertSame(604800, TimeAxis::define('week', 1)->secondsInBucket);
        self::assertSame(6, WeeklyCalendarMoment::weekday('sat'));

        $axis = TimeAxis::define(
            bucket: 'hour',
            horizon: 24,
            timeZero: new \DateTimeImmutable('2026-01-01T12:00:00+00:00'),
        );

        try {
            $axis->ceil(new \DateTimeImmutable('2026-01-01T11:59:59+00:00'));
            self::fail('Expected pre-time-zero ceil rejection.');
        } catch (\Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException $e) {
            self::assertSame('Time values must not resolve before the axis time zero.', $e->getMessage());
        }

        $calendar = WeeklyCalendar::fromMap(['3' => ['10:00']]);

        self::assertCount(1, $calendar->moments);
        self::assertSame(3, $calendar->moments[0]->isoWeekday);
    }

    public function testEarliestArrivalSolverPrefersHigherQuantityWhenArrivalsTie(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['src', 'mid1', 'mid2', 'dest'],
                'stt' => ['fs'],
            ],
            timeAxis: TimeAxis::define('hour', 24),
        )->edgeRules([
            EdgeRule::allowLabeled('collect', 'src.fs', 'mid1.fs', ['duration' => 1]),
            EdgeRule::allowLabeled('collect', 'src.fs', 'mid2.fs', ['duration' => 1]),
            EdgeRule::allowLabeled('deliver', 'mid1.fs', 'dest.fs', ['duration' => 1]),
            EdgeRule::allowLabeled('deliver', 'mid2.fs', 'dest.fs', ['duration' => 1]),
        ]);

        $flow = Flow::define('promise', static fn (Flow $flow) => $flow
            ->stepByLabeledEdges('collect')
            ->stepByLabeledEdges('deliver')
            ->constraint(static fn (MovementEdge $edge, FlowContext $context): int => 'mid1.fs' === $edge->from->key ? 1 : 2));

        $schedule = (new EarliestArrivalSolver())->schedule(new ScheduleRequest(
            state: new QuantityState($space, [['src.fs', 2]]),
            space: $space,
            flow: $flow,
            quantity: 2,
            target: 'dest.fs',
        ));

        self::assertTrue($schedule->isComplete());
        self::assertSame(2, $schedule->steps[0]->quantity);
        self::assertSame('mid2.fs', $schedule->steps[1]->edge->from->slot->key);
    }
}
