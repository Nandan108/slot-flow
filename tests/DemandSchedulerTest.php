<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Calendars\WeeklyShipmentCalendar;
use Nandan108\SlotFlow\Demand;
use Nandan108\SlotFlow\DemandLine;
use Nandan108\SlotFlow\DemandReleaseContext;
use Nandan108\SlotFlow\DemandScheduler;
use Nandan108\SlotFlow\DemandScheduleRequest;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementSchedule;
use Nandan108\SlotFlow\NamedPolicy;
use Nandan108\SlotFlow\PlannerRules\ShipmentWaveCalendarRule;
use Nandan108\SlotFlow\PlannerRules\WeeklyShipmentCalendarRule;
use Nandan108\SlotFlow\Policies\FullShipmentPolicy;
use Nandan108\SlotFlow\Policies\PartialShipmentPolicy;
use Nandan108\SlotFlow\Policies\PriorityReleasePolicy;
use Nandan108\SlotFlow\Policies\ThresholdReleasePolicy;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Results\DemandLineArrival;
use Nandan108\SlotFlow\Results\DemandLineSchedule;
use Nandan108\SlotFlow\Results\DemandShipment;
use Nandan108\SlotFlow\Results\DemandShipmentLine;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Time\TimeAxis;
use Nandan108\SlotFlow\Time\TimedMovementEdge;
use Nandan108\SlotFlow\Time\TimedSlotSpace;
use Nandan108\SlotFlow\Time\WeeklyCalendar;
use Nandan108\SlotFlow\Time\WeeklyCalendarMoment;
use Nandan108\SlotFlow\TimelineShipmentPlanner;
use PHPUnit\Framework\TestCase;

final class DemandSchedulerTest extends TestCase
{
    public function testPartialShipmentPolicyBuildsMultiLineShipmentsByArrivalTime(): void
    {
        $space = $this->makePromiseSpace();
        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: $this->makeDemand(),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: $this->inventories($space),
            releasePolicy: new PartialShipmentPolicy(),
        ));

        self::assertTrue($schedule->isComplete());
        self::assertSame([24, 48, 72, 96, 120], array_map(static fn ($shipment): int => $shipment->releaseTime, $schedule->shipments));
        self::assertSame(
            [
                ['sku3' => 3],
                ['sku2' => 1],
                ['sku3' => 4],
                ['sku1' => 1],
                ['sku2' => 2],
            ],
            array_map(fn ($shipment): array => $this->shipmentMap($shipment->lines), $schedule->shipments),
        );
    }

    public function testFullShipmentPolicyWaitsForTheLastLine(): void
    {
        $space = $this->makePromiseSpace();
        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: $this->makeDemand(),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: $this->inventories($space),
            releasePolicy: new FullShipmentPolicy(),
        ));

        self::assertCount(1, $schedule->shipments);
        $shipment = $schedule->shipments[0];
        self::assertSame(120, $schedule->firstShipmentTime());
        self::assertSame(11, $shipment->totalQuantity());
        self::assertSame(['sku1' => 1, 'sku2' => 3, 'sku3' => 7], $this->shipmentMap($schedule->shipments[0]->lines));
    }

    public function testThresholdReleasePolicyWaitsUntilEnoughOfTheOrderIsReady(): void
    {
        $space = $this->makePromiseSpace();
        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: $this->makeDemand(),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: $this->inventories($space),
            releasePolicy: new ThresholdReleasePolicy(minFillRatio: 0.5),
        ));

        self::assertSame([72, 120], array_map(static fn ($shipment): int => $shipment->releaseTime, $schedule->shipments));
        self::assertSame(['sku2' => 1, 'sku3' => 7], $this->shipmentMap($schedule->shipments[0]->lines));
        self::assertSame(['sku1' => 1, 'sku2' => 2], $this->shipmentMap($schedule->shipments[1]->lines));
    }

    public function testPriorityReleasePolicyShipsHighPriorityLinesImmediatelyAndOthersOnCompletion(): void
    {
        $space = $this->makePromiseSpace();
        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: $this->makeDemand(),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: $this->inventories($space),
            releasePolicy: new PriorityReleasePolicy(['sku3' => 0], 0),
        ));

        self::assertSame([24, 72, 96, 120], array_map(static fn ($shipment): int => $shipment->releaseTime, $schedule->shipments));
        self::assertSame(['sku3' => 3], $this->shipmentMap($schedule->shipments[0]->lines));
        self::assertSame(['sku3' => 4], $this->shipmentMap($schedule->shipments[1]->lines));
        self::assertSame(['sku1' => 1], $this->shipmentMap($schedule->shipments[2]->lines));
        self::assertSame(['sku2' => 3], $this->shipmentMap($schedule->shipments[3]->lines));
    }

    public function testShipmentPlannerCanApplyAConsolidationWindowBeforeEvaluatingReleasePolicies(): void
    {
        $space = $this->makePromiseSpace();
        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: $this->makeDemand(),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: $this->inventories($space),
            releasePolicy: new PartialShipmentPolicy(),
            consolidationWindow: 24,
        ));

        self::assertSame([48, 96, 144], array_map(static fn ($shipment): int => $shipment->releaseTime, $schedule->shipments));
        self::assertSame(['sku2' => 1, 'sku3' => 3], $this->shipmentMap($schedule->shipments[0]->lines));
        self::assertSame(['sku1' => 1, 'sku3' => 4], $this->shipmentMap($schedule->shipments[1]->lines));
        self::assertSame(['sku2' => 2], $this->shipmentMap($schedule->shipments[2]->lines));
    }

    public function testShipmentPlannerCanApplyAnOrderLevelShipmentCalendar(): void
    {
        $space = $this->makePromiseSpace();
        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: $this->makeDemand(),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: $this->inventories($space),
            releasePolicy: new PartialShipmentPolicy(),
            shipmentCalendar: new class implements \Nandan108\SlotFlow\Contracts\ShipmentCalendarInterface {
                #[\Override]
                public function releaseTime(DemandReleaseContext $context): int
                {
                    return ((int) floor($context->time / 48) + (($context->time % 48) > 0 ? 1 : 0)) * 48;
                }
            },
        ));

        self::assertSame([48, 96, 144], array_map(static fn ($shipment): int => $shipment->releaseTime, $schedule->shipments));
        self::assertSame(['sku2' => 1, 'sku3' => 3], $this->shipmentMap($schedule->shipments[0]->lines));
        self::assertSame(['sku1' => 1, 'sku3' => 4], $this->shipmentMap($schedule->shipments[1]->lines));
        self::assertSame(['sku2' => 2], $this->shipmentMap($schedule->shipments[2]->lines));
    }

    public function testShipmentPlannerCanApplyAWeeklyOrderLevelShipmentCalendar(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['wh1', 'sup1', 'cust'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: TimeAxis::define(
                'hour',
                24 * 14,
                ['day' => 24],
                timeZero: new \DateTimeImmutable('2026-03-30T00:00:00+00:00'),
            ),
        )->edgeRules([
            EdgeRule::allowLabeled('ship', 'wh1.fs', 'cust.sd', ['duration' => '1d']),
            EdgeRule::allowLabeled('ship', 'sup1.fs', 'cust.sd', ['duration' => '3d']),
        ])->flow(
            'promise',
            static fn (Flow $flow) => $flow->stepByLabeledEdges('ship'),
        );

        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: new Demand([new DemandLine('sku', 2)]),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: [
                'sku' => new QuantityState($space, [['wh1.fs', 1], ['sup1.fs', 1]]),
            ],
            releasePolicy: new PartialShipmentPolicy(),
            shipmentCalendar: new WeeklyShipmentCalendar(new WeeklyCalendar([
                WeeklyCalendarMoment::at('tue', '18:00'),
                WeeklyCalendarMoment::at('fri', '09:00'),
            ])),
        ));

        self::assertSame([42, 105], array_map(static fn ($shipment): int => $shipment->releaseTime, $schedule->shipments));
    }

    public function testShipmentPlannerCanApplyAWeeklyOrderLevelShipmentWindow(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['wh1', 'sup1', 'cust'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: TimeAxis::define(
                bucket: 'hour',
                horizon: 24 * 14,
                aliases: ['day' => 24],
                // This is a Monday, so the first Tuesday is at hour 24 and the first Friday is at hour 96
                timeZero: new \DateTimeImmutable('2026-03-30T00:00:00+00:00'),
            ),
        )->edgeRules([
            EdgeRule::allowLabeled('ship', 'wh1.fs', 'cust.sd', ['duration' => '1d']),
            EdgeRule::allowLabeled('ship', 'sup1.fs', 'cust.sd', ['duration' => '3d']),
        ])->flow(
            'promise',
            static fn (Flow $flow) => $flow->stepByLabeledEdges('ship'),
        );

        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: new Demand([new DemandLine('sku', 2)]),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: [
                'sku' => new QuantityState($space, [['wh1.fs', 1], ['sup1.fs', 1]]),
            ],
            releasePolicy: new PartialShipmentPolicy(),
            shipmentCalendar: new WeeklyShipmentCalendar(WeeklyCalendar::fromMap([
                'tue' => ['17:00-20:00'],
                'thu' => ['08:00-10:00'],
                'sat' => ['14:00-16:00'],
            ])),
        ));
        // The warehouse unit is ready after 1 day at hour 24, so it ships in the first Tuesday window (17:00) at hour 24+17=41.
        self::assertSame(41, $schedule->firstShipmentTime());
        self::assertSame(41, $schedule->shipments[0]->releaseTime);
        // The supplier unit is ready after 3 days at hour 72, so it waits for the Thursday 08:00 window at hour 72+8=80.
        self::assertSame(80, $schedule->shipments[1]->releaseTime);
    }

    public function testShipmentPlannerCanApplyEdgeAttachedShipmentCalendarRules(): void
    {
        $space = $this->makePromiseSpaceWithShipmentWaves();
        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: $this->makeDemand(),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: $this->inventories($space),
            releasePolicy: new PartialShipmentPolicy(),
        ));

        self::assertSame([48, 96, 120], array_map(static fn ($shipment): int => $shipment->releaseTime, $schedule->shipments));
        self::assertSame(['sku2' => 1, 'sku3' => 3], $this->shipmentMap($schedule->shipments[0]->lines));
        self::assertSame(['sku1' => 1, 'sku3' => 4], $this->shipmentMap($schedule->shipments[1]->lines));
        self::assertSame(['sku2' => 2], $this->shipmentMap($schedule->shipments[2]->lines));

        $lineSchedule = $schedule->lines[2] ?? null;
        self::assertNotNull($lineSchedule);
        $terminalStep = $lineSchedule->readyArrivalSteps(24)[0] ?? null;
        self::assertNotNull($terminalStep);
        self::assertCount(1, $terminalStep->edge->shipmentCalendarRules());
        self::assertInstanceOf(ShipmentWaveCalendarRule::class, $terminalStep->edge->shipmentCalendarRules()[0]);
    }

    public function testShipmentPlannerCanApplyStepLevelShipmentCalendarRules(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['wh1', 'sup1', 'cust'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: TimeAxis::define('hour', 24 * 10, ['day' => 24]),
        )->edgeRules([
            EdgeRule::allowLabeled('ship', 'wh1.fs', 'cust.sd', ['duration' => '1d']),
            EdgeRule::allowLabeled('ship', 'sup1.fs', 'cust.sd', ['duration' => '3d']),
        ])->flow(
            'promise',
            static fn (Flow $flow) => $flow
                ->stepByLabeledEdges('ship')
                ->policies(new ShipmentWaveCalendarRule(48)),
        );

        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: new Demand([new DemandLine('sku', 2)]),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: [
                'sku' => new QuantityState($space, [['wh1.fs', 1], ['sup1.fs', 1]]),
            ],
            releasePolicy: new PartialShipmentPolicy(),
        ));

        self::assertSame([48, 96], array_map(static fn ($shipment): int => $shipment->releaseTime, $schedule->shipments));
        self::assertCount(1, $schedule->lines[0]->readyArrivalSteps(24)[0]->shipmentCalendarRules());
    }

    public function testShipmentPlannerCanApplyWeeklyStepLevelShipmentWindows(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['wh1', 'sup1', 'cust'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: TimeAxis::define(
                'hour',
                24 * 14,
                ['day' => 24],
                timeZero: '2026-03-30',
            ),
        )->edgeRules([
            EdgeRule::allowLabeled('ship', 'wh1.fs', 'cust.sd', ['duration' => '1d']),
            EdgeRule::allowLabeled('ship', 'sup1.fs', 'cust.sd', ['duration' => '3d']),
        ])->flow(
            'promise',
            static fn (Flow $flow) => $flow
                ->stepByLabeledEdges('ship')
                ->policies(WeeklyShipmentCalendarRule::fromMap([
                    'tue' => ['17:00-20:00'],
                    'thu' => ['08:00-10:00'],
                ])),
        );

        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: new Demand([new DemandLine('sku', 2)]),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: [
                'sku' => new QuantityState($space, [['wh1.fs', 1], ['sup1.fs', 1]]),
            ],
            releasePolicy: new PartialShipmentPolicy(),
        ));

        self::assertSame([41, 80], array_map(static fn ($shipment): int => $shipment->releaseTime, $schedule->shipments));
    }

    public function testShipmentPlannerCanApplyWeeklyStepLevelShipmentCalendarRules(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['wh1', 'sup1', 'cust'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: TimeAxis::define(
                'hour',
                24 * 14,
                ['day' => 24],
                timeZero: new \DateTimeImmutable('2026-03-30T00:00:00+00:00'),
            ),
        )->edgeRules([
            EdgeRule::allowLabeled('ship', 'wh1.fs', 'cust.sd', ['duration' => '1d']),
            EdgeRule::allowLabeled('ship', 'sup1.fs', 'cust.sd', ['duration' => '3d']),
        ])->flow(
            'promise',
            static fn (Flow $flow) => $flow
                ->stepByLabeledEdges('ship')
                ->policies(new WeeklyShipmentCalendarRule(new WeeklyCalendar([
                    WeeklyCalendarMoment::at('tue', '18:00'),
                    WeeklyCalendarMoment::at('fri', '09:00'),
                ]))),
        );

        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: new Demand([new DemandLine('sku', 2)]),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: [
                'sku' => new QuantityState($space, [['wh1.fs', 1], ['sup1.fs', 1]]),
            ],
            releasePolicy: new PartialShipmentPolicy(),
        ));

        self::assertSame([42, 105], array_map(static fn ($shipment): int => $shipment->releaseTime, $schedule->shipments));
        self::assertCount(1, $schedule->lines[0]->readyArrivalSteps(24)[0]->shipmentCalendarRules());
    }

    public function testWeeklyShipmentCalendarRejectsUntimedSpaces(): void
    {
        $space = SlotSpace::define([
            'loc' => ['wh1', 'cust'],
            'stt' => ['fs', 'sd'],
        ])->edgeRules([
            EdgeRule::allowLabeled('ship', 'wh1.fs', 'cust.sd'),
        ])->flow(
            'ship',
            static fn (Flow $flow) => $flow->stepByLabeledEdges('ship'),
        );
        $request = new DemandScheduleRequest(
            demand: new Demand([new DemandLine('sku', 1)]),
            space: $space,
            flow: 'ship',
            target: 'cust.sd',
            statesBySubjectKey: [
                'sku' => new QuantityState($space, [['wh1.fs', 1]]),
            ],
        );
        $lineSchedule = new DemandLineSchedule(
            line: $request->demand->lines[0],
            subjectKey: 'sku',
            schedule: new MovementSchedule([], 0, []),
            target: $space->slot('cust.sd'),
            arrivals: [],
        );
        $context = new DemandReleaseContext($request, [$lineSchedule], 0, false, [], [], []);

        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage('Weekly shipment calendars require a TimeAxis on the request SlotSpace.');

        (new WeeklyShipmentCalendar(WeeklyCalendar::fromMap(['mon' => ['10:00']])))
            ->releaseTime($context);
    }

    public function testWeeklyShipmentCalendarRuleRejectsUntimedSpaces(): void
    {
        $space = SlotSpace::define([
            'loc' => ['wh1', 'cust'],
            'stt' => ['fs', 'sd'],
        ])->edgeRules([
            EdgeRule::allowLabeled('ship', 'wh1.fs', 'cust.sd'),
        ])->flow(
            'ship',
            static fn (Flow $flow) => $flow->stepByLabeledEdges('ship'),
        );
        $request = new DemandScheduleRequest(
            demand: new Demand([new DemandLine('sku', 1)]),
            space: $space,
            flow: 'ship',
            target: 'cust.sd',
            statesBySubjectKey: [
                'sku' => new QuantityState($space, [['wh1.fs', 1]]),
            ],
        );
        $lineSchedule = new DemandLineSchedule(
            line: $request->demand->lines[0],
            subjectKey: 'sku',
            schedule: new MovementSchedule([], 0, []),
            target: $space->slot('cust.sd'),
            arrivals: [],
        );
        $shipmentLine = new DemandShipmentLine('sku', 1, $lineSchedule);
        $step = new \Nandan108\SlotFlow\Results\ScheduledStep(
            'sched-1',
            new TimedMovementEdge(
                from: new \Nandan108\SlotFlow\Time\TimedSlot($space->slot('wh1.fs'), 0, 'h0', TimedSlotSpace::fromBaseSpace(
                    SlotSpace::defineTimed(
                        dimensions: ['loc' => ['wh1', 'cust'], 'stt' => ['fs', 'sd']],
                        timeAxis: TimeAxis::define('hour', 24),
                    ),
                )),
                to: new \Nandan108\SlotFlow\Time\TimedSlot($space->slot('cust.sd'), 1, 'h1', TimedSlotSpace::fromBaseSpace(
                    SlotSpace::defineTimed(
                        dimensions: ['loc' => ['wh1', 'cust'], 'stt' => ['fs', 'sd']],
                        timeAxis: TimeAxis::define('hour', 24),
                    ),
                )),
                label: 'ship',
                attributes: [],
            ),
            1,
        );
        $context = new DemandReleaseContext($request, [$lineSchedule], 0, false, [], [], []);

        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage('Weekly shipment calendar rules require a TimeAxis on the request SlotSpace.');

        (new WeeklyShipmentCalendarRule(WeeklyCalendar::fromMap(['mon' => ['10:00']])))
            ->releaseTime($context, $shipmentLine, $step, 0);
    }

    public function testShipmentPlannerOnlyAppliesShipmentCalendarRulesToTheArrivalReleasedInThatShipment(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['wh1', 'sup1', 'cust'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: TimeAxis::define('hour', 24 * 10, ['day' => 24]),
        )->edgeRules([
            EdgeRule::allowLabeled('ship', 'wh1.fs', 'cust.sd', ['duration' => '1d'])
                ->policies(new ShipmentWaveCalendarRule(48)),
            EdgeRule::allowLabeled('ship', 'sup1.fs', 'cust.sd', ['duration' => '3d']),
        ])->flow(
            'promise',
            static fn (Flow $flow) => $flow->stepByLabeledEdges('ship'),
        );

        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: new Demand([new DemandLine('sku', 2)]),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: [
                'sku' => new QuantityState($space, [['wh1.fs', 1], ['sup1.fs', 1]]),
            ],
            releasePolicy: new PartialShipmentPolicy(),
        ));

        self::assertSame([48, 72], array_map(static fn ($shipment): int => $shipment->releaseTime, $schedule->shipments));
        self::assertSame([1, 1], array_map(static fn ($shipment): int | float => $shipment->totalQuantity(), $schedule->shipments));
    }

    public function testNamedPoliciesLetEdgeRulesOverrideStepLevelPoliciesWithinTheSameCategory(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['wh1', 'cust'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: TimeAxis::define('hour', 24 * 10, ['day' => 24]),
        )->edgeRules([
            EdgeRule::allowLabeled('ship', 'wh1.fs', 'cust.sd', ['duration' => '1d'])
                ->policies(NamedPolicy::as('wave', new ShipmentWaveCalendarRule(24))),
        ])->flow(
            'promise',
            static fn (Flow $flow) => $flow
                ->stepByLabeledEdges('ship')
                ->policies(NamedPolicy::as('wave', new ShipmentWaveCalendarRule(48))),
        );

        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: new Demand([new DemandLine('sku', 1)]),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: [
                'sku' => new QuantityState($space, [['wh1.fs', 1]]),
            ],
            releasePolicy: new PartialShipmentPolicy(),
        ));

        self::assertSame(24, $schedule->firstShipmentTime());
        $calendarRules = $schedule->lines[0]->readyArrivalSteps(24)[0]->shipmentCalendarRules();
        self::assertCount(1, $calendarRules);
        self::assertInstanceOf(ShipmentWaveCalendarRule::class, $calendarRules[0]);
        self::assertSame(24, $calendarRules[0]->interval);
    }

    public function testDemandSchedulerDoesNotDoubleAllocateInventoryAcrossRepeatedSubjectLines(): void
    {
        $space = $this->makePromiseSpace();
        $schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: new Demand([
                new DemandLine('sku1', 1),
                new DemandLine('sku1', 1),
            ]),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: [
                'sku1' => new QuantityState($space, [['sup1.fs', 1]]),
            ],
            releasePolicy: new PartialShipmentPolicy(),
        ));

        self::assertFalse($schedule->isComplete());
        self::assertSame([96], array_map(static fn ($shipment): int => $shipment->releaseTime, $schedule->shipments));
        self::assertCount(1, $schedule->shipments[0]->lines);
        self::assertSame(1, $schedule->shipments[0]->totalQuantity());
        self::assertSame([['sku1', 1]], $this->shipmentLineList($schedule->shipments[0]->lines));
        self::assertSame([0, 1], array_map(static fn ($line): int | float => $line->remainingQuantity(), $schedule->lines));
    }

    public function testShipmentPlannerRejectsCalendarsThatMoveReleaseBeforeReadyTime(): void
    {
        $space = $this->makePromiseSpace();

        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage('Shipment calendar cannot move a release earlier than the candidate shipment time.');

        (new DemandScheduler())->schedule(new DemandScheduleRequest(
            demand: $this->makeDemand(),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: $this->inventories($space),
            releasePolicy: new PartialShipmentPolicy(),
            shipmentCalendar: new class implements \Nandan108\SlotFlow\Contracts\ShipmentCalendarInterface {
                #[\Override]
                public function releaseTime(DemandReleaseContext $context): int
                {
                    return $context->time - 24;
                }
            },
        ));
    }

    public function testDemandScheduleAndLineHelperMethodsCoverRemainingBranches(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['src', 'cust'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: TimeAxis::define('hour', 24),
        );
        $timedSpace = TimedSlotSpace::fromBaseSpace($space);
        $baseEdge = EdgeRule::allowLabeled('ship', 'src.fs', 'cust.sd', ['duration' => 2]);
        $space = $space->edgeRules([$baseEdge]);
        $timedEdgeA = new TimedMovementEdge(
            from: $timedSpace->slot('src.fs', 0),
            to: $timedSpace->slot('cust.sd', 2),
            baseEdge: $space->edgesByLabels(['ship'])[0],
            label: 'ship',
            attributes: ['duration' => 2],
        );
        $timedEdgeB = new TimedMovementEdge(
            from: $timedSpace->slot('src.fs', 2),
            to: $timedSpace->slot('cust.sd', 4),
            baseEdge: $space->edgesByLabels(['ship'])[0],
            label: 'ship',
            attributes: ['duration' => 2],
        );
        $movement = new MovementSchedule([
            new \Nandan108\SlotFlow\Results\ScheduledStep('sched-1', $timedEdgeA, 1),
            new \Nandan108\SlotFlow\Results\ScheduledStep('sched-2', $timedEdgeB, 2),
        ], 0, []);
        $lineSchedule = new DemandLineSchedule(
            line: new DemandLine('sku', 3),
            subjectKey: 'sku',
            schedule: $movement,
            target: $space->slot('cust.sd'),
            arrivals: [
                new DemandLineArrival(2, 1),
                new DemandLineArrival(4, 2),
            ],
        );
        $emptyLine = new DemandLineSchedule(
            line: new DemandLine('empty', 1),
            subjectKey: 'empty',
            schedule: new MovementSchedule([], 1, []),
            target: $space->slot('cust.sd'),
            arrivals: [],
        );
        $shipmentLine = new DemandShipmentLine('sku', 1, $lineSchedule);
        $shipment = new DemandShipment(3, [$shipmentLine]);
        $context = new DemandReleaseContext(
            request: new DemandScheduleRequest(
                demand: new Demand([$lineSchedule->line]),
                space: $space,
                flow: Flow::define('ship', static fn (Flow $flow) => $flow->stepByLabeledEdges('ship')),
                target: 'cust.sd',
            ),
            lineSchedules: [$lineSchedule, $emptyLine],
            time: 4,
            finalEvaluation: true,
            availableBySubject: ['sku' => 3],
            shippedBySubject: ['sku' => 1],
            shipments: [$shipment],
        );

        self::assertSame(3, $lineSchedule->fulfilledQuantity());
        self::assertSame(2, $lineSchedule->firstReadyTime());
        self::assertSame(4, $lineSchedule->completeTime());
        self::assertCount(2, $lineSchedule->readyArrivalSteps(4));
        self::assertCount(1, $lineSchedule->releasedArrivalSteps(4, 1, 2));
        self::assertNull($emptyLine->firstReadyTime());
        self::assertNull($emptyLine->completeTime());
        self::assertSame(3, $context->availableQuantity('sku'));
        self::assertSame(0, $context->availableQuantity('missing'));
        self::assertSame(1, $context->shippedQuantity('sku'));
        self::assertSame(0, $context->shippedQuantity('missing'));
        self::assertSame(2, $context->availableQuantityForLine($lineSchedule));
        self::assertSame(1, $context->shippedQuantityForLine($lineSchedule));
        self::assertSame(2, $context->remainingQuantityForLine($lineSchedule));
        self::assertSame(2.0 / 4.0, $context->fillRatio());

        $schedule = new \Nandan108\SlotFlow\DemandSchedule([$lineSchedule, $emptyLine], [$shipment]);
        self::assertFalse($schedule->isComplete());
        self::assertSame(3, $schedule->firstShipmentTime());
        self::assertSame(3, $schedule->completeTime());
        self::assertNull((new \Nandan108\SlotFlow\DemandSchedule([], []))->completeTime());
        self::assertSame([], (new FullShipmentPolicy())->release(new DemandReleaseContext(
            request: new DemandScheduleRequest(
                demand: new Demand([]),
                space: $space,
                flow: Flow::define('ship', static fn (Flow $flow) => $flow->stepByLabeledEdges('ship')),
                target: 'cust.sd',
            ),
            lineSchedules: [],
            time: 0,
            finalEvaluation: false,
            availableBySubject: [],
            shippedBySubject: [],
            shipments: [],
        )));
    }

    public function testDemandScheduleRequestSupportsUntimedIntegerStartTimesAndRejectsNonIntegerStrings(): void
    {
        $space = SlotSpace::define([
            'loc' => ['src', 'cust'],
            'stt' => ['fs', 'sd'],
        ])->flow('ship', static fn (Flow $flow) => $flow->move('src.fs', 'cust.sd'));

        $request = new DemandScheduleRequest(
            demand: new Demand([new DemandLine('sku', 1)]),
            space: $space,
            flow: 'ship',
            target: 'cust.sd',
            startTime: '12',
            consolidationWindow: -5,
        );

        self::assertSame(12, $request->startTime);
        self::assertSame(0, $request->consolidationWindow);

        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage('Demand schedule requests without a TimeAxis require an integer bucket start time.');
        new DemandScheduleRequest(
            demand: new Demand([new DemandLine('sku', 1)]),
            space: $space,
            flow: 'ship',
            target: 'cust.sd',
            startTime: 'later',
        );
    }

    public function testDemandSchedulerRejectsEmptyPerLineFlowAndTargetStrings(): void
    {
        $space = $this->makePromiseSpace();
        $scheduler = new DemandScheduler();

        try {
            /** @psalm-suppress InvalidArgument */
            $scheduler->schedule(new DemandScheduleRequest(
                demand: new Demand([new DemandLine('sku', 1, flow: '')]),
                space: $space,
                flow: 'promise',
                target: 'cust.sd',
                statesBySubjectKey: ['sku' => new QuantityState($space, [['wh1.fs', 1]])],
            ));
            self::fail('Expected empty flow rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Demand line flow must be a non-empty string.', $e->getMessage());
        }

        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage('Demand line target must be a non-empty string.');
        /** @psalm-suppress InvalidArgument */
        $scheduler->schedule(new DemandScheduleRequest(
            demand: new Demand([new DemandLine('sku', 1, target: '')]),
            space: $space,
            flow: 'promise',
            target: 'cust.sd',
            statesBySubjectKey: ['sku' => new QuantityState($space, [['wh1.fs', 1]])],
        ));
    }

    public function testShipmentWaveCalendarRuleValidatesArgumentsAndAlignsAtOffsetBoundary(): void
    {
        $rule = new ShipmentWaveCalendarRule(24, 6);
        self::assertSame(30, $rule->releaseTime(
            context: new DemandReleaseContext(
                request: new DemandScheduleRequest(
                    demand: new Demand([]),
                    space: $this->makePromiseSpace(),
                    flow: 'promise',
                    target: 'cust.sd',
                ),
                lineSchedules: [],
                time: 0,
                finalEvaluation: false,
                availableBySubject: [],
                shippedBySubject: [],
                shipments: [],
            ),
            line: new DemandShipmentLine('sku', 1, $this->makeManualLineSchedule()),
            step: $this->makeManualLineSchedule()->schedule->steps[0],
            earliestReleaseTime: 30,
        ));

        try {
            new ShipmentWaveCalendarRule(0);
            self::fail('Expected invalid interval rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Shipment wave interval must be greater than zero.', $e->getMessage());
        }

        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage('Shipment wave offset must be within the interval bounds.');
        new ShipmentWaveCalendarRule(24, 24);
    }

    public function testTimelineShipmentPlannerCanBuildExplicitContexts(): void
    {
        $planner = new TimelineShipmentPlanner();
        $lineSchedule = $this->makeManualLineSchedule();
        $request = new DemandScheduleRequest(
            demand: new Demand([$lineSchedule->line]),
            space: $lineSchedule->target->space,
            flow: Flow::define('ship', static fn (Flow $flow) => $flow->stepByLabeledEdges('ship')),
            target: $lineSchedule->target,
        );

        $context = $planner->context($request, [$lineSchedule], 3, true, ['sku' => 1], ['sku' => 0], []);

        self::assertSame(3, $context->time);
        self::assertTrue($context->finalEvaluation);
        self::assertCount(1, $context->lineSchedules);
    }

    private function makePromiseSpace(): SlotSpace
    {
        /** @var array<string, string> $durations */
        $durations = [
            'wh1'  => '1d',
            'wh2'  => '2d',
            'sup1' => '4d',
            'sup2' => '5d',
            'sup3' => '3d',
        ];

        return
            SlotSpace::defineTimed(
                dimensions: [
                    'loc' => ['sup1', 'sup2', 'sup3', 'wh1', 'wh2', 'cust'],
                    'stt' => ['fs', 'sd'],
                ],
                timeAxis: TimeAxis::define('hour', 24 * 10, ['day' => 24]),
            )
            // allow movement from suppliers and warehouses to the customer
            ->edgeRules([EdgeRule::allowLabeled('ship', 'wh*|sup*.fs', 'cust.sd')])
            // duration is derived from $durations map, using edge 'from' location as the key
            ->setDurationResolver(fn ($edge) => $durations[(string) $edge->from['loc']] ?? 0)
            // add a "promise" flow using the 'ship' edges
            ->flow('promise', static fn ($flow) => $flow->stepByLabeledEdges('ship'));
    }

    private function makePromiseSpaceWithShipmentWaves(): SlotSpace
    {
        $durations = [
            'wh1'  => '1d',
            'wh2'  => '2d',
            'sup1' => '4d',
            'sup2' => '5d',
            'sup3' => '3d',
        ];
        $WhipWave48h = new ShipmentWaveCalendarRule(48);

        return
            SlotSpace::defineTimed(
                dimensions: [
                    'loc' => ['sup1', 'sup2', 'sup3', 'wh1', 'wh2', 'cust'],
                    'stt' => ['fs', 'sd'],
                ],
                timeAxis: TimeAxis::define('hour', 24 * 10, ['day' => 24]),
            )->edgeRules([
                EdgeRule::allowLabeled('ship', 'wh*|sup*.fs', 'cust.sd'),
                EdgeRule::allowLabeled('ship', 'wh1.fs', 'cust.sd')->policies($WhipWave48h),
                EdgeRule::allowLabeled('ship', 'sup1.fs', 'cust.sd')->policies($WhipWave48h),
                EdgeRule::allowLabeled('ship', 'sup3.fs', 'cust.sd')->policies($WhipWave48h),
            ])
            // duration is derived from $durations map, using edge 'from' location as the key
            ->setDurationResolver(fn ($edge) => $durations[(string) $edge->from['loc']] ?? 0)
            // add a "promise" flow using the 'ship' edges
            ->flow('promise', static fn ($flow) => $flow->stepByLabeledEdges('ship'));
    }

    private function makeDemand(): Demand
    {
        return new Demand([
            new DemandLine('sku1', 1),
            new DemandLine('sku2', 3),
            new DemandLine('sku3', 7),
        ]);
    }

    /**
     * @return array<string, QuantityState>
     */
    private function inventories(SlotSpace $space): array
    {
        return [
            'sku1' => new QuantityState($space, [['sup1.fs', 1]]),
            'sku2' => new QuantityState($space, [['wh2.fs', 1], ['sup2.fs', 2]]),
            'sku3' => new QuantityState($space, [['wh1.fs', 3], ['sup3.fs', 4]]),
        ];
    }

    /**
     * @param list<DemandShipmentLine> $lines
     *
     * @return array<string, int|float>
     */
    private function shipmentMap(array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            $map[$line->subjectKey] = $line->quantity;
        }
        ksort($map);

        return $map;
    }

    /**
     * @param list<DemandShipmentLine> $lines
     *
     * @return list<array{string, int|float}>
     */
    private function shipmentLineList(array $lines): array
    {
        return array_map(
            static fn ($line): array => [$line->subjectKey, $line->quantity],
            $lines,
        );
    }

    private function makeManualLineSchedule(): DemandLineSchedule
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['src', 'cust'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: TimeAxis::define('hour', 24),
        )->edgeRules([
            EdgeRule::allowLabeled('ship', 'src.fs', 'cust.sd', ['duration' => 1]),
        ]);
        $timed = TimedSlotSpace::fromBaseSpace($space);
        $baseEdge = $space->edgesByLabels(['ship'])[0];
        $step = new \Nandan108\SlotFlow\Results\ScheduledStep(
            'sched-1',
            new TimedMovementEdge(
                from: $timed->slot('src.fs', 0),
                to: $timed->slot('cust.sd', 1),
                baseEdge: $baseEdge,
                label: 'ship',
                attributes: ['duration' => 1],
            ),
            1,
        );

        return new DemandLineSchedule(
            line: new DemandLine('sku', 1),
            subjectKey: 'sku',
            schedule: new MovementSchedule([$step], 0, []),
            target: $space->slot('cust.sd'),
            arrivals: [new DemandLineArrival(1, 1)],
        );
    }
}
