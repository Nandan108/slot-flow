<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Demand;
use Nandan108\SlotFlow\DemandLine;
use Nandan108\SlotFlow\DemandReleaseContext;
use Nandan108\SlotFlow\DemandScheduler;
use Nandan108\SlotFlow\DemandScheduleRequest;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\NamedPolicy;
use Nandan108\SlotFlow\PlannerRules\ShipmentWaveCalendarRule;
use Nandan108\SlotFlow\Policies\FullShipmentPolicy;
use Nandan108\SlotFlow\Policies\PartialShipmentPolicy;
use Nandan108\SlotFlow\Policies\PriorityReleasePolicy;
use Nandan108\SlotFlow\Policies\ThresholdReleasePolicy;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Time\TimeAxis;
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
            timeAxis: new TimeAxis('hour', 24 * 10, ['day' => 24]),
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

    public function testShipmentPlannerOnlyAppliesShipmentCalendarRulesToTheArrivalReleasedInThatShipment(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['wh1', 'sup1', 'cust'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: new TimeAxis('hour', 24 * 10, ['day' => 24]),
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
            timeAxis: new TimeAxis('hour', 24 * 10, ['day' => 24]),
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
                timeAxis: new TimeAxis('hour', 24 * 10, ['day' => 24]),
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
                timeAxis: new TimeAxis('hour', 24 * 10, ['day' => 24]),
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
     * @param list<\Nandan108\SlotFlow\Results\DemandShipmentLine> $lines
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
     * @param list<\Nandan108\SlotFlow\Results\DemandShipmentLine> $lines
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
}
