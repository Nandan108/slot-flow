<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Results\ScheduledStep;

/**
 * Reconcile scheduled steps against actual executed movements.
 *
 * @api
 */
final class ScheduleReconciler
{
    /**
     * Return the residual schedule after subtracting actual quantities linked by schedule step id.
     */
    public function reconcile(MovementSchedule $schedule, MovementResult $actual): MovementSchedule
    {
        /** @var array<string, int|float> $executedByStep */
        $executedByStep = [];
        foreach ($actual->events as $event) {
            if (null === $event->scheduleStepId) {
                continue;
            }

            $stepId = $event->scheduleStepId;
            /** @psalm-suppress InvalidOperand */
            $executedByStep[$stepId] = ($executedByStep[$stepId] ?? 0) + $event->quantity;
        }

        /** @var list<ScheduledStep> $remainingSteps */
        $remainingSteps = [];
        foreach ($schedule->steps as $step) {
            $executed = $executedByStep[$step->id] ?? 0;
            if ($executed <= 0) {
                $remainingSteps[] = $step;
                continue;
            }

            if ($executed >= $step->quantity) {
                continue;
            }

            /** @psalm-suppress InvalidOperand */
            $remainingSteps[] = $step->withQuantity($step->quantity - $executed);
        }

        $milestones = array_map(
            static fn (ScheduledStep $step) => $step->milestone(),
            $remainingSteps,
        );

        return new MovementSchedule($remainingSteps, $schedule->remaining, $milestones);
    }
}
