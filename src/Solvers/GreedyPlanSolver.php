<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Solvers;

use Nandan108\SlotFlow\Contracts\PlanSolverInterface;
use Nandan108\SlotFlow\Internal\FlowStep;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementPlan;
use Nandan108\SlotFlow\PlanRequest;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Results\PlannedStep;
use Nandan108\SlotFlow\Slot;

/**
 * Plans one timeless movement path/allocation with greedy semantics.
 *
 * @api
 */
final class GreedyPlanSolver extends AbstractPathSolver implements PlanSolverInterface
{
    /**
     * Build one timeless movement plan from a plan request.
     */
    #[\Override]
    public function plan(PlanRequest $request): MovementPlan
    {
        $planningState = $request->state->copy();
        $steps = $this->resolveStepEdges($request->space, $request->flow, $request->params);
        if ([] === $steps) {
            return new MovementPlan([], $request->quantity);
        }

        $remaining = $request->quantity;
        $stepId = 0;
        /** @var list<PlannedStep> $plannedSteps */
        $plannedSteps = [];

        while ($remaining > 0) {
            $slice = $this->greedyPath(
                inventory: $planningState,
                stepEdges: $steps,
                quantity: $remaining,
                target: $request->target,
                params: $request->params,
            );

            if (null === $slice || [] === $slice['path']) {
                break;
            }

            $allocated = $slice['quantity'];
            foreach ($slice['path'] as $pathStep) {
                ++$stepId;
                $plannedSteps[] = new PlannedStep(
                    'plan-'.$stepId,
                    $pathStep['edge'],
                    $allocated,
                    $pathStep['step']->policies,
                );
            }

            $planningState = $slice['inventory'];
            /** @psalm-suppress InvalidOperand */
            $remaining -= $allocated;
        }

        return new MovementPlan($plannedSteps, $remaining);
    }

    /**
     * Find the next complete timeless path in greedy policy order.
     *
     * @param list<array{step: FlowStep, edges: list<MovementEdge>}> $stepEdges
     * @param array<string, string>                                  $params
     *
     * @return ?array{
     *   path: list<array{step: FlowStep, edge: MovementEdge}>,
     *   quantity: int|float,
     *   inventory: QuantityState
     * }
     */
    private function greedyPath(
        QuantityState $inventory,
        array $stepEdges,
        int | float $quantity,
        Slot $target,
        array $params,
    ): ?array {
        return $this->walkPath(
            inventory: $inventory,
            stepEdges: $stepEdges,
            stepIndex: 0,
            quantity: $quantity,
            target: $target,
            params: $params,
            currentSlot: null,
            path: [],
        );
    }

    /**
     * @param list<array{step: FlowStep, edges: list<MovementEdge>}> $stepEdges
     * @param array<string, string>                                  $params
     * @param list<array{step: FlowStep, edge: MovementEdge}>        $path
     *
     * @return ?array{
     *   path: list<array{step: FlowStep, edge: MovementEdge}>,
     *   quantity: int|float,
     *   inventory: QuantityState
     * }
     */
    private function walkPath(
        QuantityState $inventory,
        array $stepEdges,
        int $stepIndex,
        int | float $quantity,
        Slot $target,
        array $params,
        ?Slot $currentSlot,
        array $path,
    ): ?array {
        if ($stepIndex > array_key_last($stepEdges)) {
            return [
                'path'      => $path,
                'quantity'  => $quantity,
                'inventory' => $inventory,
            ];
        }

        $stepData = $stepEdges[$stepIndex];
        $flowStep = $stepData['step'];
        $allowedEdges = $this->availableEdgesForStep(
            step: $flowStep,
            edges: $stepData['edges'],
            inventory: $inventory,
            quantity: $quantity,
            params: $params,
        );

        $isFinalStep = $stepIndex === array_key_last($stepEdges);
        foreach ($allowedEdges as $allowed) {
            $edge = $allowed['edge'];
            if (null !== $currentSlot && $edge->from->key !== $currentSlot->key) {
                continue;
            }

            if ($isFinalStep && $edge->to->key !== $target->key) {
                continue;
            }

            $movable = min($quantity, $allowed['quantity']);
            if ($movable <= 0) {
                continue;
            }

            $result = $this->walkPath(
                inventory: $this->applyMovement($inventory, $edge, $movable),
                stepEdges: $stepEdges,
                stepIndex: $stepIndex + 1,
                quantity: $movable,
                target: $target,
                params: $params,
                currentSlot: $edge->to,
                path: [...$path, ['step' => $flowStep, 'edge' => $edge]],
            );

            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param list<MovementEdge>    $edges
     * @param array<string, string> $params
     *
     * @return array<string, array{edge: MovementEdge, quantity: int|float}>
     */
    #[\Override]
    protected function availableEdgesForStep(
        FlowStep $step,
        array $edges,
        QuantityState $inventory,
        int | float $quantity,
        array $params,
    ): array {
        /** @var array<string, array{edge: MovementEdge, quantity: int|float}> $available */
        $available = [];
        foreach (parent::availableEdgesForStep($step, $edges, $inventory, $quantity, $params) as $entry) {
            $edgeId = $this->edgeKey($entry['edge']);
            $available[$edgeId] = $entry;
        }

        return $available;
    }
}
