<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Solvers;

use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeFilterPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\ExecutionSolverInterface;
use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\Internal\FlowStep;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Results\MovementEvent;
use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\FlowContext;
use Nandan108\SlotFlow\SlotSpace;

/**
 * Executes flows with deterministic greedy semantics.
 *
 * @psalm-import-type TSlotPattern from SlotSpace
 *
 * @api
 */
final class GreedyFlowSolver implements ExecutionSolverInterface
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

        /** @var list<MovementEvent> $events */
        $events = [];

        foreach ($flow->steps() as $step) {
            if ($remaining <= 0) {
                break;
            }

            $edges = $this->resolveStepEdges($space, $step, $appContext);
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

                    $movable = $this->limitMovable(
                        state: $state,
                        edge: $edge,
                        requested: $remaining,
                        quantity: $remaining,
                        step: $step,
                        subject: $subject,
                        context: $appContext,
                    );

                    if ($movable <= 0) {
                        continue;
                    }

                    $events[] = $this->applyMovement($state, $edge, $movable);
                    /** @psalm-suppress InvalidOperand, MixedOperand */
                    $remaining -= $movable;
                }

                continue;
            }

            foreach ($decisions as $decision) {
                if ($remaining <= 0) {
                    break;
                }

                $requested = min($decision->quantity, $remaining);
                $movable = $this->limitMovable(
                    state: $state,
                    edge: $decision->edge,
                    requested: $requested,
                    quantity: $remaining,
                    step: $step,
                    subject: $subject,
                    context: $appContext,
                );

                if ($movable <= 0) {
                    continue;
                }

                $events[] = $this->applyMovement($state, $decision->edge, $movable);
                /** @psalm-suppress InvalidOperand, MixedOperand */
                $remaining -= $movable;
            }
        }

        /** @psalm-suppress InvalidArgument */
        return new MovementResult($events, $remaining);
    }

    /**
     * @return list<MovementEdge>
     */
    private function resolveStepEdges(SlotSpace $space, FlowStep $step, array $context): array
    {
        if (null !== $step->edgeLabels) {
            /** @var array{params?: array<string, non-empty-string>} $context */
            $params = array_map('strval', $context['params'] ?? []);
            $labels = array_map(
                fn (string $label): string => $this->resolveStringParameter($label, $params),
                $step->edgeLabels,
            );

            return $space->edgesByLabels($labels);
        }

        /** @psalm-suppress ArgumentTypeCoercion */
        return array_values($space->edgesBetween(
            $this->resolvePatternParameters($step->from, $context),
            $this->resolvePatternParameters($step->to, $context),
        ));
    }

    /**
     * @param array<mixed>                               $context
     * @param string|array<int|string, string|null>|null $pattern
     *
     * @psalm-param TSlotPattern $pattern
     *
     * @psalm-return TSlotPattern
     */
    private function resolvePatternParameters(string | array | null $pattern, array $context): string | array | null
    {
        if (null === $pattern) {
            return null;
        }
        /** @var array{params?: array<string, non-empty-string>} $context */
        $params = array_map('strval', $context['params'] ?? []);

        if (is_string($pattern)) {
            return $this->resolveStringParameter($pattern, $params);
        }

        $resolved = [];
        foreach ($pattern as $key => $value) {
            $resolved[$key] = is_string($value)
                ? $this->resolveStringParameter($value, $params)
                : $value;
        }

        return $resolved;
    }

    /**
     * @param array<string, string> $params
     * @param non-empty-string      $value
     *
     * @psalm-return non-empty-string
     */
    private function resolveStringParameter(string $value, array $params): string
    {
        if (!$params) {
            return $value;
        }

        if (1 === preg_match('/^\{([-a-z_]*)\}$/i', $value, $matches)) {
            return $params[$matches[1]] ?? $value ?: $value;
        }

        $resolved = preg_replace_callback(
            '/\{([-a-z_]*)\}/i',
            static function (array $matches) use ($params) {
                $resolved = $params[$matches[1]] ?? null;

                return $resolved ?? "\{$matches[0]\}" ?: "\{$matches[0]\}";
            },
            $value,
        );

        return $resolved ?? $value ?: $value;
    }

    /**
     * @param list<MovementEdge> $edges
     *
     * @return list<MovementEdge>
     */
    private function filterEdges(callable | EdgeFilterPolicyInterface $policy, array $edges, FlowContext $context): array
    {
        if ($policy instanceof EdgeFilterPolicyInterface) {
            return $policy->filterEdges($context);
        }

        /** @psalm-var mixed */
        $result = $policy($context);

        if (!is_array($result)) {
            return $edges;
        }

        /** @var list<MovementEdge> $result */
        return $result;
    }

    /**
     * @param list<MovementEdge> $edges
     *
     * @return list<MovementEdge>
     */
    private function orderEdges(callable | EdgeOrderingPolicyInterface $policy, array $edges, FlowContext $context): array
    {
        if ($policy instanceof EdgeOrderingPolicyInterface) {
            return $policy->orderEdges($context);
        }

        /** @psalm-var mixed */
        $result = $policy($context);

        if (!is_array($result)) {
            return $edges;
        }

        /** @var list<MovementEdge> $result */
        return $result;
    }

    /**
     * @param list<MovementEdge> $edges
     *
     * @return list<AllocationDecision>
     */
    private function allocateEdges(callable | AllocationPolicyInterface $policy, array $edges, FlowContext $context): array
    {
        if ($policy instanceof AllocationPolicyInterface) {
            return $policy->allocate($context);
        }

        /** @psalm-var mixed */
        $result = $policy($context);

        if (!is_array($result)) {
            return [];
        }

        /** @var list<AllocationDecision> $result */
        return $result;
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

    private function applyMovement(QuantityState $state, MovementEdge $edge, int | float $quantity): MovementEvent
    {
        $initialFrom = $edge->from->isNil() ? null : $state->get($edge->from);
        $initialTo = $edge->to->isNil() ? null : $state->get($edge->to);

        if (!$edge->from->isNil()) {
            $state->add($edge->from, -$quantity);
        }

        if (!$edge->to->isNil()) {
            $state->add($edge->to, $quantity);
        }

        return new MovementEvent($edge, $quantity, $initialFrom, $initialTo);
    }
}
