<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class BatchMovementEngine
{
    public function __construct(
        private MovementEngine $engine,
    ) {
    }

    /**
     * Executes a batch of inventory movements.
     *
     * @template TVariant
     *
     * @param InventoryBatch $batch   the batch of inventory items to move
     * @param SlotSpace      $space   the slot space used to resolve cascade steps
     * @param Cascade        $cascade the cascade containing the movement rules
     * @param array<mixed>   $context execution context forwarded to the cascade engine
     * @param array<string, scalar|null> $params cascade parameter substitutions forwarded to the cascade engine
     *
     * @psalm-param InventoryBatch<TVariant> $batch
     *
     * @return InventoryBatch the batch with updated movement results for each item
     *
     * @psalm-return InventoryBatch<TVariant>
     */
    public function execute(
        InventoryBatch $batch,
        SlotSpace $space,
        Cascade $cascade,
        array $context = [],
        array $params = [],
    ): InventoryBatch {
        foreach ($batch->items() as $item) {
            $item->setMovementResult(
                $this->engine->execute(
                    $item->inventory(),
                    $space,
                    $cascade,
                    $item->quantity(),
                    appContext: $context,
                    params: $params,
                ),
            );
        }

        return $batch;
    }
}
