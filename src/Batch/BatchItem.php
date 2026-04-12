<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Batch;

use Nandan108\SlotFlow\Exceptions\SlotFlowLogicException;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\QuantityState;

/**
 * @template T
 *
 * @internal
 */
final class BatchItem
{
    private ?MovementResult $result = null;

    /**
     * Create one batch item for one subject quantity state.
     *
     * @param T             $subject
     * @param QuantityState $inventory the initial quantity state for this batch item
     */
    public function __construct(
        public readonly mixed $subject,
        public readonly int | float $quantity,
        public readonly QuantityState $inventory,
    ) {
    }

    /**
     * Return the movement result assigned to this batch item, if any.
     */
    public function movementResult(): ?MovementResult
    {
        return $this->result;
    }

    /**
     * Assign the movement result for this batch item exactly once.
     */
    public function setMovementResult(MovementResult $result): void
    {
        if (null !== $this->result) {
            throw new SlotFlowLogicException(
                'Movement result already set',
                ['has_existing_result' => true],
            );
        }

        $this->result = $result;
    }
}
