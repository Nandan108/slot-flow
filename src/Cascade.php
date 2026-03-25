<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Internal\CascadeStep;
use Nandan108\SlotFlow\Internal\CascadeStepBuilder;

/**
 * @psalm-import-type TSlotPattern from SlotSpace
 *
 * @api
 */
final class Cascade
{
    private string $name;

    /** @var list<CascadeStep> */
    private array $steps = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function define(string $name, \Closure $builder): self
    {
        $cascade = new self($name);
        $builder($cascade);

        return $cascade;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<CascadeStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function reverseIf(bool $condition, bool $flipEdges = true): self
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
            static fn (CascadeStep $step): CascadeStep => new CascadeStep(
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
     * @psalm-param TSlotPattern $from
     * @psalm-param TSlotPattern $to
     */
    public function move(string | array | null $from, string | array | null $to): CascadeStepBuilder
    {
        $this->steps[] = $step = new CascadeStep($from, $to);

        return new CascadeStepBuilder($this, $step);
    }

    /**
     * @psalm-param TSlotPattern $to
     */
    public function create(string | array | null $to): CascadeStepBuilder
    {
        $this->steps[] = $step = new CascadeStep(null, $to);

        return new CascadeStepBuilder($this, $step);
    }

    /**
     * @psalm-param TSlotPattern $from
     */
    public function destroy(string | array | null $from): CascadeStepBuilder
    {
        $this->steps[] = $step = new CascadeStep($from, null);

        return new CascadeStepBuilder($this, $step);
    }

    /**
     * Add a step that resolves its candidate edges from one or more labeled edge rules.
     *
     * @param non-empty-string ...$edgeLabels
     */
    public function stepByLabeledEdges(string ...$edgeLabels): CascadeStepBuilder
    {
        if ([] === $edgeLabels) {
            throw new SlotFlowInvalidArgumentException(
                'At least one edge label is required',
                ['edge_labels' => $edgeLabels],
            );
        }

        /** @var list<non-empty-string> $labelList */
        $labelList = array_values($edgeLabels);
        $step = new CascadeStep(null, null, $labelList);
        $this->steps[] = $step;

        return new CascadeStepBuilder($this, $step);
    }
}
