<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\MovementSchedule;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Results\MovementEvent;
use Nandan108\SlotFlow\Results\ScheduledStep;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\ScheduleRequest;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Solvers\EarliestArrivalSolver;
use Nandan108\SlotFlow\Time\TimeAxis;

/**
 * Manufacturing planning example with staged processing lead times.
 */
final class ManufacturingScheduleExample
{
    public readonly SlotSpace $space;

    public function __construct()
    {
        $this->space = SlotSpace::define(
            dimensions: [
                'loc' => ['plant', 'store'],
                'stt' => ['raw', 'wip', 'fg'],
            ],
            timeAxis: new TimeAxis(
                bucket: 'hour',
                horizon: 24 * 7,
                aliases: ['day' => 24],
            ),
        )->edgeRules([
            EdgeRule::allowLabeled('cut', 'plant.raw', 'plant.wip', ['duration' => 'd1']),
            EdgeRule::allowLabeled('assemble-slow', 'plant.wip', 'plant.fg', ['duration' => 'd2']),
            EdgeRule::allowLabeled('assemble-fast', 'plant.wip', 'plant.fg', ['duration' => 'd1']),
            EdgeRule::allowLabeled('deliver', 'plant.fg', 'store.fg', ['duration' => 'd1']),
        ])->flow(
            'produce-deliver',
            static fn (Flow $flow) => $flow
                ->stepByLabeledEdges('cut')
                ->stepByLabeledEdges('assemble-slow', 'assemble-fast')
                ->stepByLabeledEdges('deliver'),
        );
    }

    /**
     * Return the starting shop-floor quantity state.
     */
    public function startingState(): QuantityState
    {
        return new QuantityState($this->space, [['plant.raw', 5]]);
    }

    /**
     * Build an earliest-arrival schedule for finished goods to reach the store.
     */
    public function plan(int | float $quantity): MovementSchedule
    {
        return (new EarliestArrivalSolver())->schedule(new ScheduleRequest(
            state: $this->startingState(),
            space: $this->space,
            flow: 'produce-deliver',
            quantity: $quantity,
            target: 'store.fg',
        ));
    }

    /**
     * Record one actual execution event against one scheduled step.
     */
    public function executeScheduledStep(QuantityState $state, ScheduledStep $step, int | float $quantity): MovementResult
    {
        $baseEdge = $step->edge->baseEdge;
        if (null === $baseEdge) {
            throw new \LogicException('Scheduled steps in this fixture must map to a base edge.');
        }

        $initialFrom = $state->get($baseEdge->from);
        $initialTo = $state->get($baseEdge->to);

        if (!$baseEdge->from->isNil()) {
            $state->add($baseEdge->from, -$quantity);
        }

        if (!$baseEdge->to->isNil()) {
            $state->add($baseEdge->to, $quantity);
        }

        return new MovementResult([
            new MovementEvent($baseEdge, $quantity, $initialFrom, $initialTo, $step->id),
        ], 0);
    }
}
