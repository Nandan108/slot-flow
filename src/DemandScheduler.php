<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Contracts\ScheduleSolverInterface;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Results\DemandLineArrival;
use Nandan108\SlotFlow\Results\DemandLineSchedule;
use Nandan108\SlotFlow\Solvers\EarliestArrivalSolver;

/**
 * Schedules a multi-line demand by composing per-line schedules and an order release policy.
 *
 * @api
 */
final class DemandScheduler
{
    /**
     * Create one demand scheduler.
     */
    public function __construct(
        /** Underlying single-line scheduling solver used for each demand line. */
        private readonly ScheduleSolverInterface $solver = new EarliestArrivalSolver(),
    ) {
    }

    /**
     * Build one order-level demand schedule from a multi-line request.
     */
    public function schedule(DemandScheduleRequest $request): DemandSchedule
    {
        /** @var list<DemandLineSchedule> $lineSchedules */
        $lineSchedules = [];
        $statesBySubjectKey = $request->statesBySubjectKey;

        foreach ($request->demand->lines as $line) {
            $subjectKey = $request->space->subjectKey($line->subject);
            $state = $statesBySubjectKey[$subjectKey] ?? new QuantityState($request->space);
            $flow = $line->flow ?? $request->flow;
            $target = $line->target ?? $request->target;

            if (is_string($flow) && '' === $flow) {
                throw new SlotFlowInvalidArgumentException('Demand line flow must be a non-empty string.', ['line' => $line]);
            }

            if (is_string($target) && '' === $target) {
                throw new SlotFlowInvalidArgumentException('Demand line target must be a non-empty string.', ['line' => $line]);
            }

            $movementSchedule = $this->solver->schedule(new ScheduleRequest(
                state: $state,
                space: $request->space,
                flow: $flow,
                quantity: $line->quantity,
                target: $target,
                startTime: $request->startTime,
                params: [...$request->params, ...array_map('strval', $line->params)],
            ));

            $resolvedTarget = $request->space->slot($target);
            $lineSchedules[] = new DemandLineSchedule(
                line: $line,
                subjectKey: $subjectKey,
                schedule: $movementSchedule,
                target: $resolvedTarget,
                arrivals: $this->targetArrivals($movementSchedule, $resolvedTarget),
            );
            $statesBySubjectKey[$subjectKey] = $this->applySchedule($state, $movementSchedule);
        }

        return new DemandSchedule(
            lines: $lineSchedules,
            shipments: $request->shipmentPlanner->plan($request, $lineSchedules),
        );
    }

    /**
     * Extract target-arrival events from one line schedule.
     *
     * @return list<DemandLineArrival>
     */
    private function targetArrivals(MovementSchedule $schedule, Slot $target): array
    {
        /** @var list<DemandLineArrival> $arrivals */
        $arrivals = [];

        foreach ($schedule->steps as $step) {
            if ($step->edge->to->slot->key !== $target->key) {
                continue;
            }

            $arrivals[] = new DemandLineArrival($step->arrivalTime(), $step->quantity);
        }

        usort($arrivals, static fn (DemandLineArrival $left, DemandLineArrival $right): int => $left->time <=> $right->time);

        return $arrivals;
    }

    /**
     * Apply one planned schedule to a working inventory state so later demand
     * lines for the same subject cannot re-use already allocated quantities.
     */
    private function applySchedule(QuantityState $state, MovementSchedule $schedule): QuantityState
    {
        $updated = $state->copy();

        foreach ($schedule->steps as $step) {
            $edge = $step->edge->baseEdge;
            if (null === $edge) {
                continue;
            }

            if (!$edge->from->isNil()) {
                $updated->add($edge->from, -$step->quantity);
            }

            if (!$edge->to->isNil()) {
                $updated->add($edge->to, $step->quantity);
            }
        }

        return $updated;
    }
}
