<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeFilterPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Internal\CascadeStep;
use Nandan108\SlotFlow\Results\MovementEvent;
use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\CascadeContext;

/**
 * Executes cascades against inventory with deterministic greedy semantics.
 *
 * @template TQtty of int|float
 *
 * @psalm-import-type TSlotPattern from SlotSpace
 * @psalm-import-type TSlotKey from SlotSpace
 * @psalm-import-type TDimensionValuePattern from SlotSpace
 *
 * @api
 */
final class MovementEngine
{
    /**
     * Execute one cascade for one inventory subject.
     *
     * @param array<mixed>               $appContext
     * @param array<string, scalar|null> $params
     *
     * @psalm-param TQtty $quantity
     *
     * @psalm-return MovementResult<TQtty>
     */
    public function execute(
        Inventory $inventory,
        SlotSpace $space,
        string | Cascade $cascade,
        int | float $quantity,
        mixed $subject = null,
        array $appContext = [],
        array $params = [],
    ): MovementResult {
        $remaining = $quantity;
        if ([] !== $params) {
            $appContext['params'] = $params;
        }

        /** @var list<MovementEvent<TQtty>> $events */
        $events = [];

        if (is_string($cascade)) {
            $cascade = $space->getCascade($cascade);
        }

        foreach ($cascade->steps() as $step) {
            if ($remaining <= 0) {
                break;
            }

            // Resolve edges for the step based on the current context and inventory state
            $edges = $this->resolveStepEdges($space, $step, $appContext);
            $stepContext = new CascadeContext($space, $edges, $inventory, $remaining, $subject, $appContext);

            foreach ($step->filterPolicies as $policy) {
                if (!is_callable($policy) && !$policy instanceof EdgeFilterPolicyInterface) {
                    continue;
                }

                $edges = $this->filterEdges($policy, $edges, $stepContext);
                $stepContext = new CascadeContext($space, $edges, $inventory, $remaining, $subject, $appContext);
            }

            foreach ($step->orderingPolicies as $policy) {
                if (!is_callable($policy) && !$policy instanceof EdgeOrderingPolicyInterface) {
                    continue;
                }

                $edges = $this->orderEdges($policy, $edges, $stepContext);
                $stepContext = new CascadeContext($space, $edges, $inventory, $remaining, $subject, $appContext);
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
                        inventory: $inventory,
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

                    /** @var TQtty $movable */
                    $events[] = $this->applyMovement($inventory, $edge, $movable);
                    /** @psalm-suppress InvalidOperand, MixedOperand */
                    $remaining -= $movable;
                }

                continue;
            }

            foreach ($decisions as $decision) {
                if ($remaining <= 0) {
                    break;
                }

                /** @var TQtty $requested */
                $requested = min($decision->quantity, $remaining);
                $movable = $this->limitMovable(
                    inventory: $inventory,
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

                /** @var TQtty $movable */
                $events[] = $this->applyMovement($inventory, $decision->edge, $movable);
                /** @psalm-suppress InvalidOperand, MixedOperand */
                $remaining -= $movable;
            }
        }

        /** @psalm-suppress InvalidArgument */
        $result = new MovementResult($events, $remaining);

        /** @var MovementResult<TQtty> $result */
        return $result;
    }

    /**
     * @return list<MovementEdge>
     */
    private function resolveStepEdges(SlotSpace $space, CascadeStep $step, array $context): array
    {
        if (null !== $step->edgeLabels) {
            /** @var array{params?: array<string, non-empty-string>} $context */
            $params = array_map('strval', $context['params'] ?? []);
            // Resolve edge labels to actual edges in the space
            $labels = array_map(
                // fn (string $label): string => $this->resolveRequiredStringParameter($label, $context),
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
     * Resolve a string parameter that may contain placeholders in the form of {placeholderName}.
     *
     * @param array<string, string> $params
     * @param non-empty-string      $value
     *
     * @psalm-return non-empty-string
     */
    private function resolveStringParameter(string $value, array $params): string
    {
        // no params passe: return as is
        if (!$params) {
            return $value;
        }

        // simple placeholder case: whole value is a single placeholder
        if (1 === preg_match('/^\{([-a-z_]*)\}$/i', $value, $matches)) {
            return $params[$matches[1]] ?? $value ?: $value;
        }

        // general case: replace any placeholder within the string
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
    private function filterEdges(callable | EdgeFilterPolicyInterface $policy, array $edges, CascadeContext $context): array
    {
        if ($policy instanceof EdgeFilterPolicyInterface) {
            return $policy->filterEdges($context);
        }

        /** @var list<MovementEdge> $filtered */
        $filtered = $policy($context);

        return $filtered;
    }

    /**
     * @param list<MovementEdge> $edges
     *
     * @return list<MovementEdge>
     */
    private function orderEdges(callable | EdgeOrderingPolicyInterface $policy, array $edges, CascadeContext $context): array
    {
        if ($policy instanceof EdgeOrderingPolicyInterface) {
            return $policy->orderEdges($context);
        }

        /** @var list<MovementEdge> $ordered */
        $ordered = $policy($context);

        return $ordered;
    }

    /**
     * @param list<MovementEdge> $edges
     *
     * @return list<AllocationDecision>
     */
    private function allocateEdges(callable | AllocationPolicyInterface $policy, array $edges, CascadeContext $context): array
    {
        if ($policy instanceof AllocationPolicyInterface) {
            return $policy->allocate($context);
        }

        /** @var list<AllocationDecision> $decisions */
        $decisions = $policy($context);

        return $decisions;
    }

    private function limitMovable(
        Inventory $inventory,
        MovementEdge $edge,
        int | float $requested,
        int | float $quantity,
        CascadeStep $step,
        mixed $subject,
        array $context,
    ): int | float {
        $space = $edge->from->space;
        $movable = $requested;

        if (!$edge->from->isNil()) {
            $available = $inventory->get($edge->from);
            $movable = min($movable, $available);
        }

        foreach ($step->quantityConstraintPolicies as $policy) {
            if (!is_callable($policy) && !$policy instanceof QttyConstraintPolicyInterface) {
                continue;
            }

            $stepContext = new CascadeContext($space, [$edge], $inventory, $quantity, $subject, $context);
            $limit = $policy instanceof QttyConstraintPolicyInterface
                ? $policy->constraint($edge, $stepContext)
                : $policy($edge, $stepContext);

            if (!is_int($limit) && !is_float($limit)) {
                continue;
            }

            $movable = min($movable, $limit);
        }

        return max(0, $movable);
    }

    private function applyMovement(
        Inventory $inventory,
        MovementEdge $edge,
        int | float $quantity,
    ): MovementEvent {
        $event = new MovementEvent(
            $edge,
            $quantity,
            $edge->from->isNil() ? null : $inventory->get($edge->from),
            $edge->to->isNil() ? null : $inventory->get($edge->to),
        );

        if (!$edge->from->isNil()) {
            $inventory->add($edge->from, -$quantity);
        }

        if (!$edge->to->isNil()) {
            $inventory->add($edge->to, $quantity);
        }

        return $event;
    }
}
