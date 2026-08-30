<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Solvers;

use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeFilterPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\ExecutionSolverInterface;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Results\MovementEvent;
use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\FlowContext;
use Nandan108\SlotFlow\SlotSpace;

/**
 * Executes movement flows directly with greedy semantics.
 *
 * Shares its edge resolution, policy application and quantity limiting with the planning solvers
 * through {@see AbstractPathSolver}. That is not merely to avoid repetition: a plan is only worth
 * anything if it predicts what execution will do, so the two must resolve edges, apply policies and
 * cap quantities by the same code, not by two copies that can drift apart.
 *
 * @psalm-import-type TSlotPattern from SlotSpace
 *
 * @api
 */
final class GreedyFlowSolver extends AbstractPathSolver implements ExecutionSolverInterface
{
    /**
     * @param array<mixed>               $appContext
     * @param array<string, scalar|null> $params
     */
    #[\Override]
    public function execute(
        QuantityState $state,
        SlotSpace $space,
        Flow $flow,
        int | float $quantity,
        mixed $subject = null,
        array $appContext = [],
        array $params = [],
    ): MovementResult {
        $remaining = $quantity;
        if ([] !== $params) {
            $appContext['params'] = $params;
        }

        $resolvedParams = $this->paramsFromContext($appContext);

        /** @var list<MovementEvent> $events */
        $events = [];

        foreach ($flow->steps() as $step) {
            if ($remaining <= 0) {
                break;
            }

            $edges = $this->resolveOneStepEdges($space, $step, $resolvedParams);
            $stepContext = new FlowContext($space, $edges, $state, $remaining, $subject, $appContext);

            foreach ($step->filterPolicies as $policy) {
                if (!is_callable($policy) && !$policy instanceof EdgeFilterPolicyInterface) {
                    continue;
                }

                $edges = $this->filterEdges($policy, $stepContext);
                $stepContext = new FlowContext($space, $edges, $state, $remaining, $subject, $appContext);
            }

            foreach ($step->orderingPolicies as $policy) {
                if (!is_callable($policy) && !$policy instanceof EdgeOrderingPolicyInterface) {
                    continue;
                }

                $edges = $this->orderEdges($policy, $stepContext);
                $stepContext = new FlowContext($space, $edges, $state, $remaining, $subject, $appContext);
            }

            /** @var list<AllocationDecision> $decisions */
            $decisions = [];
            if ([] !== $step->allocationPolicies) {
                foreach ($step->allocationPolicies as $policy) {
                    if (!is_callable($policy) && !$policy instanceof AllocationPolicyInterface) {
                        continue;
                    }

                    $decisions = $this->allocateEdges($policy, $stepContext);
                }
            }

            if ([] === $decisions) {
                foreach ($edges as $edge) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $movable = $this->limitMovable($state, $edge, $remaining, $remaining, $step, $appContext, $subject);
                    if ($movable <= 0) {
                        continue;
                    }

                    $events[] = $this->applyMove($state, $edge, $movable);
                    /** @psalm-suppress InvalidOperand */
                    $remaining -= $movable;
                }

                continue;
            }

            foreach ($decisions as $decision) {
                if ($remaining <= 0) {
                    break;
                }

                $requested = min($decision->quantity, $remaining);
                $movable = $this->limitMovable($state, $decision->edge, $requested, $remaining, $step, $appContext, $subject);
                if ($movable <= 0) {
                    continue;
                }

                $events[] = $this->applyMove($state, $decision->edge, $movable);
                /** @psalm-suppress InvalidOperand */
                $remaining -= $movable;
            }
        }

        return new MovementResult($events, $remaining);
    }

    /**
     * Extract the string-valued execute parameters from an application context.
     *
     * @param array<mixed> $context
     *
     * @return array<string, string>
     */
    private function paramsFromContext(array $context): array
    {
        /** @var array<array-key, scalar|null> $rawParams */
        $rawParams = is_array($context['params'] ?? null) ? $context['params'] : [];
        $params = [];
        foreach ($rawParams as $name => $value) {
            if (!is_string($name) || '' === $name || null === $value) {
                continue;
            }

            $params[$name] = (string) $value;
        }

        return $params;
    }

    /**
     * Apply one movement to the working state and record it as an event.
     *
     * Distinct from {@see AbstractPathSolver::applyMovement()}, which returns a fresh state for
     * path exploration: execution advances one working state and reports what happened.
     */
    private function applyMove(
        QuantityState $state,
        MovementEdge $edge,
        int | float $quantity,
    ): MovementEvent {
        $initialFrom = null;
        $initialTo = null;

        if (!$edge->from->isNil()) {
            $initialFrom = $state->get($edge->from);
            $state->add($edge->from, -$quantity);
        }

        if (!$edge->to->isNil()) {
            $initialTo = $state->get($edge->to);
            $state->add($edge->to, $quantity);
        }

        return new MovementEvent(
            edge: $edge,
            quantity: $quantity,
            initialFrom: $initialFrom,
            initialTo: $initialTo,
        );
    }
}
