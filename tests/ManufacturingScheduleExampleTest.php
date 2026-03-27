<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Contracts\ScheduleSolverInterface;
use Nandan108\SlotFlow\Results\ScheduledStep;
use Nandan108\SlotFlow\ScheduleReconciler;
use Nandan108\SlotFlow\Solvers\EarliestArrivalSolver;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ManufacturingScheduleExample;

final class ManufacturingScheduleExampleTest extends TestCase
{
    public function testEarliestArrivalSolverImplementsScheduleSolverInterface(): void
    {
        self::assertInstanceOf(ScheduleSolverInterface::class, new EarliestArrivalSolver());
    }

    public function testEarliestArrivalSolverBuildsAScheduleWithMilestonesAndTimedDeltas(): void
    {
        $example = new ManufacturingScheduleExample();
        $schedule = $example->plan(4);

        self::assertTrue($schedule->isComplete());
        self::assertCount(3, $schedule->steps);
        self::assertCount(3, $schedule->milestones);
        self::assertSame(
            ['plant.raw@h0', 'plant.wip@h24', 'plant.fg@h48'],
            array_map(static fn (ScheduledStep $step): string => $step->edge->from->key, $schedule->steps),
        );
        self::assertSame('store.fg@h72', $schedule->lastMilestone()?->slot->key);
        self::assertSame(
            [
                'plant.raw@h0' => -4,
                'store.fg@h72' => 4,
            ],
            $this->deltaMap($schedule->deltas()),
        );
    }

    public function testScheduleReconcilerKeepsResidualStepsAfterActualExecution(): void
    {
        $example = new ManufacturingScheduleExample();
        $schedule = $example->plan(4);
        $state = $example->startingState();
        $firstStep = $schedule->steps[0];
        $actual = $example->executeScheduledStep($state, $firstStep, 2);

        self::assertSame($firstStep->id, $actual->events[0]->scheduleStepId);
        self::assertSame(
            $firstStep->id,
            $actual->ledgerEntries()[0]->context['schedule_step_id'] ?? null,
        );
        self::assertSame(3, $state->get('plant.raw'));
        self::assertSame(2, $state->get('plant.wip'));

        $residual = (new ScheduleReconciler())->reconcile($schedule, $actual);

        self::assertCount(3, $residual->steps);
        self::assertSame(2, $residual->step($firstStep->id)?->quantity);
        self::assertSame(4, $residual->steps[1]->quantity);
        self::assertSame('arrive:deliver', $residual->lastMilestone()?->name);
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
            $map[$delta->slot->key] = $delta->delta;
        }

        return $map;
    }
}
