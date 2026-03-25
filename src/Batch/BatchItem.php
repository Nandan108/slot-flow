<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Batch;

use Nandan108\SlotFlow\Inventory;
use Nandan108\SlotFlow\MovementResult;

/**
 * @template T
 *
 * @internal
 */
final class BatchItem
{
    private ?MovementResult $result = null;

    /**
     * @param T         $subject
     * @param Inventory $inventory the initial inventory state for this batch item
     */
    public function __construct(
        public readonly mixed $subject,
        public readonly int $quantity,
        public readonly Inventory $inventory,
    ) {
    }

    public function movementResult(): ?MovementResult
    {
        return $this->result;
    }

    public function setMovementResult(MovementResult $result): void
    {
        if (null !== $this->result) {
            throw new \LogicException('Movement result already set');
        }

        $this->result = $result;
    }
}
