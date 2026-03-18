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
     * @param InventoryBatch<TVariant> $batch the batch of inventory items to move
     * @param MovementPlan             $plan  the plan containing the path and constraints for the movement
     *
     * @return InventoryBatch<TVariant> the batch with updated movement results for each item
     */
    public function execute(
        InventoryBatch $batch,
        MovementPlan $plan,
    ): InventoryBatch {
        foreach ($batch->items() as $item) {
            $item->setMovementResult(
                $this->engine->execute(
                    $item->inventory(),
                    $plan->path(),
                    $item->quantity(),
                    $plan->constraints(),
                ),
            );
        }

        return $batch;
    }

    /**
     * Executes a batch of inventory movements using a resolver for path and constraints.
     *
     * @template TVariant
     *
     * @param InventoryBatch<TVariant>                 $batch    the batch of inventory items to move
     * @param Policy|callable(BatchItem): MovementPlan $resolver
     *
     * @return InventoryBatch<TVariant>
     */
    public function executeWithResolver(
        InventoryBatch $batch,
        Policy | callable $resolver,
    ) {
        foreach ($batch->items() as $item) {
            $plan = is_callable($resolver)
                ? $resolver($item)
                : $resolver->resolve($item);

            $item->setMovementResult(
                $this->engine->execute(
                    $item->inventory(),
                    $plan->path(),
                    $item->quantity(),
                    $plan->constraints(),
                ),
            );
        }

        return $batch;
    }
}
