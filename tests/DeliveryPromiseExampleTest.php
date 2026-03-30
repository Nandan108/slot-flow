<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Contracts\ScheduleSolverInterface;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\MovementSchedule;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Results\ScheduledStep;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\ScheduleRequest;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Solvers\EarliestArrivalSolver;
use Nandan108\SlotFlow\Time\TimeAxis;
use Nandan108\SlotFlow\Time\TimedDurationContext;
use Nandan108\SlotFlow\Time\TimedMovementEdge;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\DeliveryPromiseExample;

final class DeliveryPromiseExampleTest extends TestCase
{
    public function testEarliestArrivalSolverImplementsScheduleSolverInterface(): void
    {
        self::assertInstanceOf(ScheduleSolverInterface::class, new EarliestArrivalSolver());
    }

    public function testEarliestArrivalSolverBuildsADeliveryPromiseSchedule(): void
    {
        $example = new DeliveryPromiseExample([
            'sup-wh1'  => '5d',
        ]);
        $schedule = $example->plan(5);

        self::assertTrue($schedule->isComplete());
        self::assertCount(10, $schedule->steps);
        self::assertCount(10, $schedule->milestones);
        self::assertSame(
            [
                'wh2._.P.fs@h0',
                'wh2.wh1.P.fs@h0',
                'wh1._.P.sd@2d',
                'wh1._.P.fs@2d8h',
                'wh1.cust.P.sd@2d16h',
                'sup._.S.fs@h0',
                'sup.wh1.S.sd@4d',
                'wh1._.P.sd@9d',
                'wh1._.P.fs@9d8h',
                'wh1.cust.P.sd@9d16h',
            ],
            array_map(static fn (ScheduledStep $step): string => $step->edge->from->humanKey(), $schedule->steps),
        );
        self::assertSame('cust._.P.sd@11d16h', $schedule->lastMilestone()?->slot->humanKey());
        self::assertSame(
            [
                'wh2._.P.fs@h0'      => -2,
                'cust._.P.sd@4d16h'  => 2,
                'sup._.S.fs@h0'      => -3,
                'cust._.P.sd@11d16h' => 3,
            ],
            $this->deltaMap($schedule->deltas()),
        );
    }

    public function testExecuteScheduledStepAppliesTheUnderlyingBaseMovement(): void
    {
        $example = new DeliveryPromiseExample();
        $schedule = $example->plan(5);
        $state = $example->startingState();
        $firstStep = $schedule->steps[0];
        $actual = $example->executeScheduledStep($state, $firstStep, 1);

        self::assertCount(1, $actual->events);
        self::assertSame(1, $state->get('wh2._.P.fs'));
        self::assertSame(1, $state->get('wh2.wh1.P.fs'));
        self::assertSame($firstStep->edge->baseEdge?->from->key, $actual->events[0]->edge->from->key);
        self::assertSame($firstStep->edge->baseEdge?->to->key, $actual->events[0]->edge->to->key);
    }

    public function testEarliestArrivalSolverCanScheduleCreateFlowsFromNil(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['cust'],
                'stt' => ['fs'],
            ],
            timeAxis: TimeAxis::define('hour', 24),
        );
        $flow = Flow::define('create-stock', static fn (Flow $flow) => $flow->create('cust.fs'));

        $schedule = (new EarliestArrivalSolver())->schedule(new ScheduleRequest(
            state: new QuantityState($space),
            space: $space,
            flow: $flow,
            quantity: 3,
            target: 'cust.fs',
        ));

        self::assertTrue($schedule->isComplete());
        self::assertCount(1, $schedule->steps);
        self::assertTrue($schedule->steps[0]->edge->from->isNil());
        self::assertSame('cust.fs@h0', $schedule->steps[0]->edge->to->key);
    }

    public function testEarliestArrivalSolverCanHonorDispatchCalendarsFromTheRequestStartTime(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['wh1', 'cust'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: TimeAxis::define('hour', 24 * 7, ['day' => 24]),
        )->edgeRules([
            EdgeRule::allowLabeled('ship', 'wh1.fs', 'cust.sd', ['duration' => '1d']),
        ])->flow(
            'ship',
            static fn (Flow $flow) => $flow->stepByLabeledEdges('ship'),
        )->setDispatchCalendar(static function (MovementEdge $edge, TimedDurationContext $context): int {
            if ($context->earliestDispatchTime % 24 < 16) {
                return $context->earliestDispatchTime;
            }

            return ((int) floor($context->earliestDispatchTime / 24) + 1) * 24 + 8;
        });

        $schedule = (new EarliestArrivalSolver())->schedule(new ScheduleRequest(
            state: new QuantityState($space, [['wh1.fs', 1]]),
            space: $space,
            flow: 'ship',
            quantity: 1,
            target: 'cust.sd',
            startTime: 17,
        ));

        self::assertSame(32, $schedule->steps[0]->departureTime());
        self::assertSame(56, $schedule->steps[0]->arrivalTime());
        self::assertSame('cust.sd@2d8h', $schedule->lastMilestone()?->slot->humanKey());
    }

    public function testEarliestArrivalSolverHonorsStepFiltersUsedByExecution(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['src', 'dest'],
                'stt' => ['fs'],
            ],
            timeAxis: TimeAxis::define('hour', 24),
        )->edgeRules([
            EdgeRule::allowLabeled('ship', 'src.fs', 'dest.fs'),
        ]);
        $flow = Flow::define(
            'blocked-shipment',
            static fn (Flow $flow) => $flow
                ->stepByLabeledEdges('ship')
                ->filter(static fn (): array => []),
        );

        $state = new QuantityState($space, [['src.fs', 1]]);
        $execution = (new MovementEngine())->execute($state->copy(), $space, $flow, 1);
        $schedule = (new EarliestArrivalSolver())->schedule(new ScheduleRequest(
            state: $state,
            space: $space,
            flow: $flow,
            quantity: 1,
            target: 'dest.fs',
        ));

        self::assertSame(0, count($execution->events));
        self::assertSame(1, $execution->remaining);
        self::assertFalse($schedule->isComplete());
        self::assertCount(0, $schedule->steps);
        self::assertSame(1, $schedule->remaining);
    }

    public function testMovementScheduleHelpersCoverEmptyLookupsAndCollapsedDeltas(): void
    {
        $schedule = (new DeliveryPromiseExample())->plan(2);
        $lastMilestoneIndex = array_key_last($schedule->milestones);

        self::assertSame($schedule->milestones[0]->name, $schedule->firstMilestone()?->name);
        self::assertNotNull($lastMilestoneIndex);
        self::assertSame($schedule->milestones[$lastMilestoneIndex]->name, $schedule->lastMilestone()?->name);
        self::assertNull($schedule->step('missing-step'));

        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['cust'],
                'stt' => ['fs'],
            ],
            timeAxis: TimeAxis::define('hour', 24),
        );
        $flow = Flow::define('create-stock', static fn (Flow $flow) => $flow->create('cust.fs'));
        $createSchedule = (new EarliestArrivalSolver())->schedule(new ScheduleRequest(
            state: new QuantityState($space),
            space: $space,
            flow: $flow,
            quantity: 2,
            target: 'cust.fs',
        ));
        $stepA = $createSchedule->steps[0];
        $reverseEdge = new TimedMovementEdge(
            from: $stepA->edge->to,
            to: $stepA->edge->from,
            baseEdge: $stepA->edge->baseEdge,
            label: 'undo',
            attributes: ['duration' => 0],
        );
        $collapsed = new MovementSchedule(
            steps: [
                $stepA,
                new ScheduledStep('undo-'.$stepA->id, $reverseEdge, $stepA->quantity),
            ],
            remaining: 0,
            milestones: [],
        );

        self::assertSame([], $collapsed->deltas());
        self::assertNull($collapsed->firstMilestone());
        self::assertNull($collapsed->lastMilestone());
    }

    public function testScheduledStepHelpersCoverDurationMilestonesAndNilDeltas(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['cust'],
                'stt' => ['fs'],
            ],
            timeAxis: TimeAxis::define('hour', 24),
        );
        $flow = Flow::define('create-stock', static fn (Flow $flow) => $flow->create('cust.fs'));
        $schedule = (new EarliestArrivalSolver())->schedule(new ScheduleRequest(
            state: new QuantityState($space),
            space: $space,
            flow: $flow,
            quantity: 3,
            target: 'cust.fs',
        ));
        $step = $schedule->steps[0];

        self::assertSame(0, $step->departureTime());
        self::assertSame(0, $step->arrivalTime());
        self::assertSame(0, $step->duration());
        self::assertSame('arrive', $step->milestone('arrive')->name);
        self::assertSame('arrive', $step->milestone()->name);
        self::assertCount(1, $step->deltas());
        self::assertSame('cust.fs@h0', $step->deltas()[0]->slot->key);
        self::assertSame(1.5, $step->withQuantity(1.5)->quantity);
    }

    public function testEarliestArrivalSolverHandlesEmptyFlowsAndUnreachableTargets(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['src', 'dest'],
                'stt' => ['fs'],
            ],
            timeAxis: TimeAxis::define('hour', 24),
        )->edgeRules([
            EdgeRule::allowLabeled('ship', 'src.fs', 'dest.fs', ['duration' => 1]),
        ]);

        $emptyFlowSchedule = (new EarliestArrivalSolver())->schedule(new ScheduleRequest(
            state: new QuantityState($space, [['src.fs', 2]]),
            space: $space,
            flow: Flow::define('noop', static fn (Flow $flow) => $flow),
            quantity: 2,
            target: 'dest.fs',
        ));

        self::assertSame([], $emptyFlowSchedule->steps);
        self::assertSame(2, $emptyFlowSchedule->remaining);

        $wrongTargetSchedule = (new EarliestArrivalSolver())->schedule(new ScheduleRequest(
            state: new QuantityState($space, [['src.fs', 2]]),
            space: $space,
            flow: Flow::define('ship', static fn (Flow $flow) => $flow->stepByLabeledEdges('ship')),
            quantity: 2,
            target: 'src.fs',
        ));

        self::assertSame([], $wrongTargetSchedule->steps);
        self::assertSame(2, $wrongTargetSchedule->remaining);
        self::assertFalse($wrongTargetSchedule->isComplete());
    }

    /**
     * @param list<\Nandan108\SlotFlow\Results\TimedQuantityStateDelta> $deltas
     *
     * @return array<string, int|float>
     */
    private function deltaMap(array $deltas): array
    {
        $map = [];
        foreach ($deltas as $delta) {
            $map[$delta->slot->humanKey()] = $delta->delta;
        }

        return $map;
    }
}
