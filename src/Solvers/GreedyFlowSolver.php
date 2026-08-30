<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Solvers;

use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeFilterPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\ExecutionSolverInterface;
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
 * Executes movement flows directly with greedy semantics.
 *
 * Shares its edge resolution, policy application and quantity limiting with the planning solvers
 * through {@see AbstractPathSolver}. That is not merely to avoid repetition: a plan is only worth
 * anything if it predicts what execution will do, so the two must resolve edges, apply policies and
 * cap quantities by the same code, not by two copies that can drift apart.
 *
 * @psalm-import-type TSlotPattern from SlotSpace
 * @psalm-import-type TTraceEdge from MovementResult
 * @psalm-import-type TTraceStep from MovementResult
 *
 * @api
 */
final class GreedyFlowSolver extends AbstractPathSolver implements ExecutionSolverInterface
{
    /**
     * @param bool $trace collect a per-step decision record on the result; see
     *                    {@see MovementResult::trace()}. Off by default, because it costs memory
     *                    per step and most executions never look at one
     */
    public function __construct(private readonly bool $trace = false)
    {
    }

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
        /** @var list<TTraceStep> $trace */
        $trace = [];

        foreach ($flow->steps() as $stepIndex => $step) {
            if ($remaining <= 0) {
                break;
            }

            $edges = $this->resolveOneStepEdges($space, $step, $resolvedParams);
            $candidates = $this->trace ? self::edgeKeys($edges) : [];
            $stepContext = new FlowContext($space, $edges, $state, $remaining, $subject, $appContext);

            foreach ($step->filterPolicies as $policy) {
                if (!is_callable($policy) && !$policy instanceof EdgeFilterPolicyInterface) {
                    continue;
                }

                $edges = $this->filterEdges($policy, $stepContext);
                $stepContext = new FlowContext($space, $edges, $state, $remaining, $subject, $appContext);
            }

            $afterFilters = $this->trace ? self::edgeKeys($edges) : [];

            foreach ($step->orderingPolicies as $policy) {
                if (!is_callable($policy) && !$policy instanceof EdgeOrderingPolicyInterface) {
                    continue;
                }

                $edges = $this->orderEdges($policy, $stepContext);
                $stepContext = new FlowContext($space, $edges, $state, $remaining, $subject, $appContext);
            }

            $afterOrdering = $this->trace ? self::edgeKeys($edges) : [];

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

            $remainingBefore = $remaining;
            /** @var list<TTraceEdge> $edgeTrace */
            $edgeTrace = [];

            if ([] === $decisions) {
                foreach ($edges as $edge) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $available = $this->trace ? $this->availableOn($state, $edge, $remaining) : 0;
                    $movable = $this->limitMovable($state, $edge, $remaining, $remaining, $step, $appContext, $subject);
                    if ($movable <= 0) {
                        if ($this->trace) {
                            $edgeTrace[] = self::edgeTrace($edge, $available, $movable, 0);
                        }

                        continue;
                    }

                    $events[] = $this->applyMove($state, $edge, $movable);
                    /** @psalm-suppress InvalidOperand */
                    $remaining -= $movable;

                    if ($this->trace) {
                        $edgeTrace[] = self::edgeTrace($edge, $available, $movable, $movable);
                    }
                }

                if ($this->trace) {
                    $trace[] = self::stepTrace($stepIndex, $step, $candidates, $afterFilters, $afterOrdering, $edgeTrace, $remainingBefore, $remaining, false);
                }

                continue;
            }

            foreach ($decisions as $decision) {
                if ($remaining <= 0) {
                    break;
                }

                $requested = min($decision->quantity, $remaining);
                $available = $this->trace ? $this->availableOn($state, $decision->edge, $requested) : 0;
                $movable = $this->limitMovable($state, $decision->edge, $requested, $remaining, $step, $appContext, $subject);
                if ($movable <= 0) {
                    if ($this->trace) {
                        $edgeTrace[] = self::edgeTrace($decision->edge, $available, $movable, 0, $decision->quantity);
                    }

                    continue;
                }

                $events[] = $this->applyMove($state, $decision->edge, $movable);
                /** @psalm-suppress InvalidOperand */
                $remaining -= $movable;

                if ($this->trace) {
                    $edgeTrace[] = self::edgeTrace($decision->edge, $available, $movable, $movable, $decision->quantity);
                }
            }

            if ($this->trace) {
                $trace[] = self::stepTrace($stepIndex, $step, $candidates, $afterFilters, $afterOrdering, $edgeTrace, $remainingBefore, $remaining, true);
            }
        }

        return new MovementResult($events, $remaining, $this->trace ? $trace : null);
    }

    /**
     * @param list<MovementEdge> $edges
     *
     * @return list<string>
     */
    private static function edgeKeys(array $edges): array
    {
        return array_map(static fn (MovementEdge $edge): string => (string) $edge, $edges);
    }

    /**
     * One candidate edge's outcome.
     *
     * The three quantities are what make a trace diagnostic rather than decorative: `available` is
     * what the source held, `movable` what survived the step's quantity constraints, and `moved`
     * what the outstanding request actually took. Each drop to zero has a different cause.
     *
     * @return array<mixed>
     *
     * @psalm-return TTraceEdge
     */
    private static function edgeTrace(
        MovementEdge $edge,
        int | float $available,
        int | float $movable,
        int | float $moved,
        int | float | null $allocated = null,
    ): array {
        $entry = [
            'edge'      => (string) $edge,
            'label'     => $edge->label,
            'available' => $available,
            'movable'   => max(0, $movable),
            'moved'     => $moved,
        ];

        if (null !== $allocated) {
            $entry['allocated'] = $allocated;
        }

        return $entry;
    }

    /**
     * One flow step's decision record.
     *
     * @param list<string>     $candidates
     * @param list<string>     $afterFilters
     * @param list<string>     $afterOrdering
     * @param list<TTraceEdge> $edgeTrace
     *
     * @return array<mixed>
     *
     * @psalm-return TTraceStep
     */
    private static function stepTrace(
        int $index,
        FlowStep $step,
        array $candidates,
        array $afterFilters,
        array $afterOrdering,
        array $edgeTrace,
        int | float $remainingBefore,
        int | float $remainingAfter,
        bool $allocated,
    ): array {
        /** @psalm-suppress InvalidOperand */
        $applied = $remainingBefore - $remainingAfter;

        return [
            'step'            => $index,
            'from'            => $step->from,
            'to'              => $step->to,
            'edgeLabels'      => $step->edgeLabels,
            'candidates'      => $candidates,
            'afterFilters'    => $afterFilters,
            'afterOrdering'   => $afterOrdering,
            'byAllocation'    => $allocated,
            'edges'           => $edgeTrace,
            'remainingBefore' => $remainingBefore,
            'remainingAfter'  => $remainingAfter,
            'applied'         => $applied,
        ];
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
