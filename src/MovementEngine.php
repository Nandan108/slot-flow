<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Contracts\ExecutionSolverInterface;
use Nandan108\SlotFlow\Solvers\GreedyFlowSolver;

/**
 * Executes flows against quantity state via a pluggable solver.
 *
 * @api
 */
final class MovementEngine
{
    /**
     * Create one movement engine around the provided execution solver.
     */
    public function __construct(
        private readonly ExecutionSolverInterface $solver = new GreedyFlowSolver(),
    ) {
    }

    /**
     * Execute one flow for one subject.
     *
     * The quantity state passed in is **not** modified: the solver works against a private copy,
     * and the movement is reported as events and deltas on the returned result. That makes
     * execution a pure computation — safe to run speculatively, to compare two flows against the
     * same starting state, or to abandon on failure without leaving the caller's state half-moved.
     *
     * To obtain the state that results from a movement, apply the result:
     * `$after = $engine->execute($before, ...)->applyTo($before);`
     *
     * @param array<mixed>               $appContext
     * @param array<string, scalar|null> $params
     */
    public function execute(
        QuantityState $inventory,
        SlotSpace $space,
        string | Flow $cascade,
        int | float $quantity,
        mixed $subject = null,
        array $appContext = [],
        array $params = [],
    ): MovementResult {
        if (is_string($cascade)) {
            $cascade = $space->getFlow($cascade);
        }

        // The solver advances a state as it consumes edges, so it needs a mutable working copy.
        // Copying here rather than in each solver keeps the guarantee at the one boundary every
        // caller goes through, instead of depending on which solver is wired in.
        $workingState = $inventory->copy();

        return $this->solver->execute($workingState, $space, $cascade, $quantity, $subject, $appContext, $params);
    }
}
