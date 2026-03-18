<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * @template T
 */
final class BatchItem
{
    private ?MovementResult $result = null;

    /**
     * @param T         $variant
     * @param Inventory $inventory the initial inventory state for this batch item
     */
    public function __construct(
        private mixed $variant,
        private int $quantity,
        private Inventory $inventory,
    ) {
    }

    /** @return T */
    public function variant(): mixed
    {
        return $this->variant;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function inventory(): Inventory
    {
        return $this->inventory;
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
