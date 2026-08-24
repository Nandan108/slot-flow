<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Solvers;

use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeFilterPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\ExecutionSolverInterface;
use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\Internal\FlowStep;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Results\MovementEvent;
use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\FlowContext;
use Nandan108\SlotFlow\Slot;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Solvers\Concerns\ResolvesFlowParameters;

/**
 * Executes movement flows directly with greedy semantics.
 *
 * @psalm-import-type TSlotPattern from SlotSpace
 *
 * @api
 */
final class GreedyFlowSolver implements ExecutionSolverInterface
{
    use ResolvesFlowParameters;

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

        /** @var list<MovementEvent> $events */
        $events = [];

        foreach ($flow->steps() as $step) {
            if ($remaining <= 0) {
                break;
            }

            $edges = $this->resolveMoveEdges($space, $step, $appContext);
            $stepContext = new FlowContext($space, $edges, $state, $remaining, $subject, $appContext);

            foreach ($step->filterPolicies as $policy) {
                if (!is_callable($policy) && !$policy instanceof EdgeFilterPolicyInterface) {
                    continue;
                }

                $edges = $this->filterEdges($policy, $edges, $stepContext);
                $stepContext = new FlowContext($space, $edges, $state, $remaining, $subject, $appContext);
            }

            foreach ($step->orderingPolicies as $policy) {
                if (!is_callable($policy) && !$policy instanceof EdgeOrderingPolicyInterface) {
                    continue;
                }

                $edges = $this->orderEdges($policy, $edges, $stepContext);
                $stepContext = new FlowContext($space, $edges, $state, $remaining, $subject, $appContext);
            }

            /** @var list<AllocationDecision> $decisions */
            $decisions = [];
            if ([] !== $step->allocationPolicies) {
                foreach ($step->allocationPolicies as $policy) {
                    if (!is_callable($policy) && !$policy instanceof AllocationPolicyInterface) {
                        continue;
                    }

                    $decisions = $this->allocateEdges($policy, $edges, $stepContext);
                }
            }

            if ([] === $decisions) {
                foreach ($edges as $edge) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $movable = $this->limitMovable($state, $edge, $remaining, $remaining, $step, $subject, $appContext);
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
                $movable = $this->limitMovable($state, $decision->edge, $requested, $remaining, $step, $subject, $appContext);
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
     * @param array<mixed> $context
     *
     * @return list<MovementEdge>
     */
    private function resolveMoveEdges(SlotSpace $space, FlowStep $step, array $context): array
    {
        if (null !== $step->edgeLabels) {
            $params = $this->resolveStringParams($context);
            $labels = array_values(array_filter(
                array_map(
                    fn (string $label): string => $this->resolveStringParameter($label, $params),
                    $step->edgeLabels,
                ),
                static fn (string $label): bool => '' !== $label,
            ));

            return $space->edgesByLabels($labels);
        }

        return array_values($space->edgesBetween(
            $this->resolvePatternParameters($step->from, $context),
            $this->resolvePatternParameters($step->to, $context),
        ));
    }

    /**
     * @param array<mixed> $context
     *
     * @psalm-param Slot|TSlotPattern $pattern
     *
     * @psalm-return TSlotPattern
     */
    private function resolvePatternParameters(Slot | string | array | null $pattern, array $context): string | array | null
    {
        if (null === $pattern) {
            return null;
        }

        if ($pattern instanceof Slot) {
            return $pattern->key;
        }

        $params = $this->resolveStringParams($context);

        if (is_string($pattern)) {
            $resolved = $this->resolvePatternValue($pattern, $params, null);
            if ('' === $resolved) {
                throw new SlotFlowInvalidArgumentException('Resolved slot pattern cannot be empty string.');
            }

            return $resolved;
        }

        /** @var array<int<0, max>|non-empty-string, non-empty-string|null> $resolved */
        $resolved = [];
        foreach ($pattern as $key => $value) {
            if (!is_string($value)) {
                $resolved[$key] = $value;
                continue;
            }

            $resolvedValue = $this->resolvePatternValue($value, $params, $key);
            if ('' === $resolvedValue) {
                throw new SlotFlowInvalidArgumentException('Resolved slot pattern value cannot be empty string.');
            }

            $resolved[$key] = $resolvedValue;
        }

        return $resolved;
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<string, string>
     */
    private function resolveStringParams(array $context): array
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
     * @param list<MovementEdge>                                                    $edges
     * @param (callable(FlowContext): list<MovementEdge>)|EdgeFilterPolicyInterface $policy
     *
     * @return list<MovementEdge>
     */
    private function filterEdges(callable | EdgeFilterPolicyInterface $policy, array $edges, FlowContext $context): array
    {
        if ($policy instanceof EdgeFilterPolicyInterface) {
            return $policy->filterEdges($context);
        }

        return $policy($context);
    }

    /**
     * @param list<MovementEdge>                                                      $edges
     * @param (callable(FlowContext): list<MovementEdge>)|EdgeOrderingPolicyInterface $policy
     *
     * @return list<MovementEdge>
     */
    private function orderEdges(callable | EdgeOrderingPolicyInterface $policy, array $edges, FlowContext $context): array
    {
        if ($policy instanceof EdgeOrderingPolicyInterface) {
            return $policy->orderEdges($context);
        }

        return $policy($context);
    }

    /**
     * @param list<MovementEdge>                                                          $edges
     * @param (callable(FlowContext): list<AllocationDecision>)|AllocationPolicyInterface $policy
     *
     * @return list<AllocationDecision>
     */
    private function allocateEdges(callable | AllocationPolicyInterface $policy, array $edges, FlowContext $context): array
    {
        if ($policy instanceof AllocationPolicyInterface) {
            return $policy->allocate($context);
        }

        return $policy($context);
    }

    /**
     * @param array<mixed> $context
     */
    private function limitMovable(
        QuantityState $state,
        MovementEdge $edge,
        int | float $requested,
        int | float $quantity,
        FlowStep $step,
        mixed $subject,
        array $context,
    ): int | float {
        $available = $edge->from->isNil()
            ? $requested
            : min($requested, $state->get($edge->from));

        $limit = $available;
        $stepContext = new FlowContext($edge->from->space, [$edge], $state, $quantity, $subject, $context);

        foreach ($step->quantityConstraintPolicies as $policy) {
            if (!is_callable($policy) && !$policy instanceof QttyConstraintPolicyInterface) {
                continue;
            }

            $policyLimit = $policy instanceof QttyConstraintPolicyInterface
                ? $policy->constraint($edge, $stepContext)
                : $policy($edge, $stepContext);

            if (!is_int($policyLimit) && !is_float($policyLimit)) {
                continue;
            }

            $limit = min($limit, $policyLimit);
        }

        return max(0, $limit);
    }

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
