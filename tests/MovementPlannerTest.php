<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeFilterPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\PlannerRuleInterface;
use Nandan108\SlotFlow\Contracts\PlanSolverInterface;
use Nandan108\SlotFlow\Contracts\PolicyInterface;
use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Contracts\ShipmentCalendarRuleInterface;
use Nandan108\SlotFlow\DemandReleaseContext;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementPlan;
use Nandan108\SlotFlow\MovementPlanner;
use Nandan108\SlotFlow\NamedPolicy;
use Nandan108\SlotFlow\PlanRequest;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Results\PlannedStep;
use Nandan108\SlotFlow\Results\QuantityStateDelta;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\FlowContext;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Solvers\GreedyPlanSolver;
use PHPUnit\Framework\TestCase;

final class MovementPlannerTest extends TestCase
{
    public function testGreedyPlanSolverImplementsPlanSolverInterface(): void
    {
        self::assertInstanceOf(PlanSolverInterface::class, new GreedyPlanSolver());
    }

    public function testMovementPlannerBuildsATimelessMultiStepPlanWithoutMutatingInventory(): void
    {
        $space = SlotSpace::define([
            'loc' => ['sup', 'wh1', 'cust'],
            'stt' => ['fs', 'sd'],
        ])->edgeRules([
            EdgeRule::allowLabeled('receive', 'sup.fs', 'wh1.fs'),
            EdgeRule::allowLabeled('ship', 'wh1.fs', 'cust.sd'),
        ])->flow(
            'source-order',
            static fn (Flow $flow) => $flow
                ->stepByLabeledEdges('receive')
                ->stepByLabeledEdges('ship'),
        );

        $inventory = new QuantityState($space, [['sup.fs', 3]]);
        $plan = (new MovementPlanner())->plan(
            inventory: $inventory,
            space: $space,
            flow: 'source-order',
            quantity: 2,
            target: 'cust.sd',
        );

        self::assertTrue($plan->isComplete());
        self::assertCount(2, $plan->steps);
        self::assertSame('(sup.fs) -> (wh1.fs)', (string) $plan->steps[0]->edge);
        self::assertSame('(wh1.fs) -> (cust.sd)', (string) $plan->steps[1]->edge);
        self::assertSame(2, $plan->steps[0]->quantity);
        self::assertSame(2, $plan->steps[1]->quantity);
        self::assertSame(3, $inventory->get('sup.fs'));
        self::assertSame(0, $inventory->get('wh1.fs'));
        self::assertSame(0, $inventory->get('cust.sd'));
        self::assertSame(
            [
                'sup.fs'  => -2,
                'cust.sd' => 2,
            ],
            $this->deltaMap($plan->deltas()),
        );
    }

    public function testMovementPlannerCanGreedilySplitAcrossSourcesInPolicyOrder(): void
    {
        $space = SlotSpace::define([
            'loc' => ['wh1', 'wh2', 'cust'],
            'stt' => ['fs', 'sd'],
        ])->edgeRules([
            EdgeRule::allow('wh1.fs', 'cust.sd'),
            EdgeRule::allow('wh2.fs', 'cust.sd'),
        ]);
        $flow = Flow::define(
            'ship',
            static fn (Flow $flow) => $flow
                ->move('wh1|wh2.fs', 'cust.sd')
                ->orderBy(static fn ($context): array => array_reverse($context->edges)),
        );

        $inventory = new QuantityState($space, [
            ['wh1.fs', 2],
            ['wh2.fs', 2],
        ]);
        $plan = (new MovementPlanner())->plan($inventory, $space, $flow, 3, 'cust.sd');

        self::assertTrue($plan->isComplete());
        self::assertCount(2, $plan->steps);
        self::assertSame('wh2.fs', $plan->steps[0]->edge->from->key);
        self::assertSame(2, $plan->steps[0]->quantity);
        self::assertSame('wh1.fs', $plan->steps[1]->edge->from->key);
        self::assertSame(1, $plan->steps[1]->quantity);
    }

    public function testMovementPlanHelpersCoverEmptyAndCollapsedLookups(): void
    {
        $space = SlotSpace::define([
            'loc' => ['src', 'dest'],
            'stt' => ['fs'],
        ]);
        $forward = new MovementEdge($space->slot('src.fs'), $space->slot('dest.fs'));
        $reverse = new MovementEdge($space->slot('dest.fs'), $space->slot('src.fs'));
        $collapsed = new MovementPlan([
            new PlannedStep('plan-1', $forward, 2),
            new PlannedStep('plan-2', $reverse, 2),
        ], 0);

        self::assertTrue($collapsed->isComplete());
        self::assertSame([], $collapsed->deltas());
        self::assertNull($collapsed->step('missing'));
        self::assertSame('plan-1', $collapsed->step('plan-1')?->id);

        $emptySpace = SlotSpace::define([
            'loc' => ['src', 'dest'],
            'stt' => ['fs'],
        ]);
        $emptyFlow = new Flow('empty');
        $empty = (new GreedyPlanSolver())->plan(new PlanRequest(
            state: new QuantityState($emptySpace, [['src.fs', 1]]),
            space: $emptySpace,
            flow: $emptyFlow,
            quantity: 1,
            target: 'dest.fs',
        ));

        self::assertFalse($empty->isComplete());
        self::assertSame(1, $empty->remaining);
        self::assertSame([], $empty->steps);
    }

    public function testPlannedStepHelpersCoverPolicyBucketsAndQuantityCopies(): void
    {
        $space = SlotSpace::define([
            'loc' => ['src', 'dest'],
            'stt' => ['fs'],
        ]);
        $plannerRule = new class implements PlannerRuleInterface, PolicyInterface {
        };
        $shipmentRule = new class implements ShipmentCalendarRuleInterface {
            #[\Override]
            public function releaseTime(
                DemandReleaseContext $context,
                \Nandan108\SlotFlow\Results\DemandShipmentLine $line,
                \Nandan108\SlotFlow\Results\ScheduledStep $step,
                int $earliestReleaseTime,
            ): int {
                return $earliestReleaseTime;
            }
        };
        $edge = (new MovementEdge($space->slot('src.fs'), $space->slot('dest.fs')))->meta([
            ...\Nandan108\SlotFlow\PolicyBuckets::mergeEdgeAttributes([], [$shipmentRule]),
        ]);
        $step = new PlannedStep(
            id: 'plan-1',
            edge: $edge,
            quantity: 2,
            policies: [NamedPolicy::as('planner', $plannerRule)],
        );

        $copy = $step->withQuantity(5);

        self::assertSame(5, $copy->quantity);
        self::assertCount(2, $step->policies());
        self::assertCount(2, $step->plannerRules());
        self::assertCount(1, $step->shipmentCalendarRules());
    }

    public function testGreedyPlanSolverCoversInterfacePoliciesParamResolutionAndBacktracking(): void
    {
        $space = SlotSpace::define([
            'loc' => ['sup1', 'sup2', 'wh1', 'wh2', 'cust', 'overflow'],
            'stt' => ['fs', 'sd'],
        ])->edgeRules([
            EdgeRule::allowLabeled('collect-a', 'sup1.fs', 'wh1.fs'),
            EdgeRule::allowLabeled('collect-a', 'sup2.fs', 'wh2.fs'),
            EdgeRule::allowLabeled('deliver', 'wh2.fs', 'overflow.sd'),
            EdgeRule::allowLabeled('deliver', 'wh2.fs', 'cust.sd'),
        ]);

        $filter = new class implements EdgeFilterPolicyInterface {
            #[\Override]
            public function filterEdges(FlowContext $ctx): array
            {
                return $ctx->edges;
            }
        };
        $order = new class implements EdgeOrderingPolicyInterface {
            #[\Override]
            public function orderEdges(FlowContext $ctx): array
            {
                $edges = $ctx->edges;
                usort(
                    $edges,
                    static fn (MovementEdge $left, MovementEdge $right): int => strcmp($right->to->key, $left->to->key),
                );

                return $edges;
            }
        };
        $allocation = new class implements AllocationPolicyInterface {
            #[\Override]
            public function allocate(FlowContext $ctx): array
            {
                return [
                    new AllocationDecision($ctx->edges[0], 0),
                    new AllocationDecision($ctx->edges[1], $ctx->quantity),
                ];
            }
        };
        $constraint = new class implements QttyConstraintPolicyInterface {
            #[\Override]
            public function constraint(MovementEdge $edge, FlowContext $ctx): int | float
            {
                return 1;
            }
        };

        $flow = Flow::define('plan-with-policies', fn (Flow $flow) => $flow
            ->stepByLabeledEdges('{collect_label}')
            ->filter($filter)
            ->constraint($constraint)
            ->stepByLabeledEdges('deliver')
            ->filter($filter)
            ->orderBy($order)
            ->allocate($allocation)
            ->constraint(static fn (): string => 'ignore-me')
            ->constraint($constraint));

        $inventory = new QuantityState($space, [
            ['sup1.fs', 1],
            ['sup2.fs', 1],
        ]);
        $plan = (new GreedyPlanSolver())->plan(new PlanRequest(
            state: $inventory,
            space: $space,
            flow: $flow,
            quantity: 1,
            target: 'cust.sd',
            params: [
                'collect_label' => 'collect-a',
                'target_loc'    => 'cust',
            ],
        ));

        self::assertTrue($plan->isComplete());
        self::assertCount(2, $plan->steps);
        self::assertSame('sup2.fs', $plan->steps[0]->edge->from->key);
        self::assertSame('wh2.fs', $plan->steps[1]->edge->from->key);
    }

    public function testGreedyPlanSolverCanPlanCreateFlowsFromNil(): void
    {
        $space = SlotSpace::define([
            'loc' => ['cust'],
            'stt' => ['fs'],
        ]);
        $flow = Flow::define('create', static fn (Flow $flow) => $flow->create('cust.fs'));

        $plan = (new GreedyPlanSolver())->plan(new PlanRequest(
            state: new QuantityState($space),
            space: $space,
            flow: $flow,
            quantity: 3,
            target: 'cust.fs',
        ));

        self::assertTrue($plan->isComplete());
        self::assertTrue($plan->steps[0]->edge->from->isNil());
        self::assertSame(3, $plan->steps[0]->quantity);
    }

    /**
     * @param list<QuantityStateDelta> $deltas
     *
     * @return array<string, int|float>
     */
    private function deltaMap(array $deltas): array
    {
        $map = [];
        foreach ($deltas as $delta) {
            $map[$delta->slot->key] = $delta->delta;
        }

        return $map;
    }
}
