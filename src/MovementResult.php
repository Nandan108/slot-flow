<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Results\LedgerEntry;
use Nandan108\SlotFlow\Results\MovementEvent;
use Nandan108\SlotFlow\Results\QuantityStateDelta;

/**
 * Immutable summary of one flow execution.
 *
 * @psalm-import-type TSlotPattern from SlotSpace
 *
 * @psalm-type TTraceEdge = array{edge: string, label: ?string, available: int|float, movable: int|float, moved: int|float, allocated?: int|float}
 * @psalm-type TTraceStep = array{step: int, from: TSlotPattern, to: TSlotPattern, edgeLabels: ?list<non-empty-string>, candidates: list<string>, afterFilters: list<string>, afterOrdering: list<string>, byAllocation: bool, edges: list<TTraceEdge>, remainingBefore: int|float, remainingAfter: int|float, applied: int|float}
 *
 * @api
 */
final class MovementResult
{
    /**
     * @param list<MovementEvent> $events
     * @param ?list<array<mixed>> $trace  per-step decision record, when the solver was asked for one
     *
     * @psalm-param ?list<TTraceStep> $trace
     */
    public function __construct(
        public readonly array $events,
        public readonly int | float $remaining,
        private readonly ?array $trace = null,
    ) {
    }

    /**
     * Return the per-step decision trace, or null when the solver was not collecting one.
     *
     * Answers "why did it move *that*" — and, more often, "why did it move nothing". Each step
     * records its candidate edges, what the filters and ordering did to them, and per edge the
     * three numbers that separate the usual causes: `available` (what the source held),
     * `movable` (what survived the quantity constraints) and `moved` (what the remaining quantity
     * actually took). Zero available means an empty source; available above zero with movable at
     * zero means a policy capped it; movable above zero with moved at zero means the request was
     * already satisfied.
     *
     * Collection is opt-in, since a trace costs memory per step and most executions never look at
     * one: `new MovementEngine(new GreedyFlowSolver(trace: true))`.
     *
     * @return ?list<array<mixed>>
     *
     * @psalm-return ?list<TTraceStep>
     */
    public function trace(): ?array
    {
        return $this->trace;
    }

    /**
     * Return true when the requested quantity was fully satisfied.
     */
    public function isComplete(): bool
    {
        // Quantities are int|float throughout, so the comparison has to be float-safe: a fully
        // satisfied float movement leaves 0.0 remaining, which is not identical to int 0.
        return 0.0 === (float) $this->remaining;
    }

    /**
     * Aggregate per-slot quantity-state deltas for this result.
     *
     * The output is stable in first-seen slot order, which makes it suitable
     * for deterministic persistence and testing.
     *
     * @return list<QuantityStateDelta>
     */
    public function deltas(): array
    {
        /** @var array<string, QuantityStateDelta> $deltasBySlot */
        $deltasBySlot = [];
        /** @var list<string> $slotOrder */
        $slotOrder = [];

        foreach ($this->events as $event) {
            foreach ($event->deltas() as $delta) {
                $slotKey = $delta->slot->key;

                if (!isset($deltasBySlot[$slotKey])) {
                    $deltasBySlot[$slotKey] = $delta;
                    $slotOrder[] = $slotKey;
                    continue;
                }

                $existing = $deltasBySlot[$slotKey];
                /** @psalm-suppress InvalidOperand */
                $mergedDelta = $existing->delta + $delta->delta;

                $deltasBySlot[$slotKey] = new QuantityStateDelta(
                    $existing->slot,
                    $mergedDelta,
                );
            }
        }

        /** @var list<QuantityStateDelta> $deltas */
        $deltas = [];
        foreach ($slotOrder as $slotKey) {
            $delta = $deltasBySlot[$slotKey];
            if (0.0 === (float) $delta->delta) {
                continue;
            }

            $deltas[] = $delta;
        }

        return $deltas;
    }

    /**
     * Return a copy of the given quantity state with this result's net deltas applied.
     *
     * Execution computes movement without touching the state it was handed, so applying a result
     * is an explicit step the caller takes when it wants the resulting state rather than the
     * ledger. The input is left untouched, which is what makes a result safe to hold, inspect,
     * compare against alternatives, or discard.
     */
    public function applyTo(QuantityState $state): QuantityState
    {
        $updated = $state->copy();

        foreach ($this->deltas() as $delta) {
            $updated->add($delta->slot, $delta->delta);
        }

        return $updated;
    }

    /**
     * Convert each movement event to a ledger entry.
     *
     * @param array<string, mixed> $context
     *
     * @return list<LedgerEntry>
     */
    public function ledgerEntries(array $context = []): array
    {
        return array_map(
            static fn (MovementEvent $event): LedgerEntry => $event->ledgerEntry($context),
            $this->events,
        );
    }
}
