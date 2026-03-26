<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Internal\FlowStep;
use Nandan108\SlotFlow\Internal\FlowStepBuilder;

/**
 * Named ordered set of movement steps that can be executed by a solver.
 *
 * @psalm-import-type TSlotPattern from SlotSpace
 *
 * @psalm-consistent-constructor
 *
 * @api
 */
class Flow
{
    private string $name;

    /** @var list<FlowStep> */
    private array $steps = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Build a flow fluently in one call.
     */
    public static function define(string $name, \Closure $builder): static
    {
        $flow = new static($name);
        $builder($flow);

        return $flow;
    }

    /**
     * Return the flow name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Return the compiled list of movement steps.
     *
     * @return list<FlowStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * Conditionally reverse step order, optionally flipping edge direction too.
     */
    public function reverseIf(bool $condition, bool $flipEdges = true): static
    {
        if (!$condition) {
            return clone $this;
        }

        $reversed = clone $this;
        $steps = array_reverse($this->steps);

        if (!$flipEdges) {
            $reversed->steps = $steps;

            return $reversed;
        }

        $reversed->steps = array_map(
            static fn (FlowStep $step): FlowStep => new FlowStep(
                from: $step->to,
                to: $step->from,
                edgeLabels: $step->edgeLabels,
                orderingPolicies: $step->orderingPolicies,
                filterPolicies: $step->filterPolicies,
                quantityConstraintPolicies: $step->quantityConstraintPolicies,
                allocationPolicies: $step->allocationPolicies,
            ),
            $steps,
        );

        return $reversed;
    }

    /**
     * Add a movement step from one slot pattern to another.
     *
     * @psalm-param TSlotPattern $from
     * @psalm-param TSlotPattern $to
     */
    public function move(string | array | null $from, string | array | null $to): FlowStepBuilder
    {
        $this->steps[] = $step = new FlowStep($from, $to);

        return new FlowStepBuilder($this, $step);
    }

    /**
     * Add a creation step from nil into matching destination slots.
     *
     * @psalm-param TSlotPattern $to
     */
    public function create(string | array | null $to): FlowStepBuilder
    {
        $this->steps[] = $step = new FlowStep(null, $to);

        return new FlowStepBuilder($this, $step);
    }

    /**
     * Add a destruction step from matching source slots into nil.
     *
     * @psalm-param TSlotPattern $from
     */
    public function destroy(string | array | null $from): FlowStepBuilder
    {
        $this->steps[] = $step = new FlowStep($from, null);

        return new FlowStepBuilder($this, $step);
    }

    /**
     * Add a step that resolves its candidate edges from one or more labeled edge rules.
     *
     * @param non-empty-string ...$edgeLabels
     */
    public function stepByLabeledEdges(string ...$edgeLabels): FlowStepBuilder
    {
        if ([] === $edgeLabels) {
            throw new SlotFlowInvalidArgumentException(
                'At least one edge label is required',
                ['edge_labels' => $edgeLabels],
            );
        }

        /** @var list<non-empty-string> $labelList */
        $labelList = array_values($edgeLabels);
        $step = new FlowStep(null, null, $labelList);
        $this->steps[] = $step;

        return new FlowStepBuilder($this, $step);
    }
}
