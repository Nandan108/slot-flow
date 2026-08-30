<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Batch;

use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\SlotSpace;

/**
 * Executes one flow across all items in a quantity-state batch.
 *
 * @api
 */
final class BatchMovementEngine
{
    /**
     * Create one batch movement engine around a single-item engine.
     */
    public function __construct(
        private MovementEngine $engine,
    ) {
    }

    /**
     * Executes a batch of quantity-state movements.
     *
     * @template TSubject
     *
     * @param QuantityStateBatch         $batch   the batch of quantity-state items to move
     * @param SlotSpace                  $space   the slot space used to resolve flow steps
     * @param string|Flow                $cascade the flow containing the movement rules
     * @param array<mixed>               $context execution context forwarded to the movement engine
     * @param array<string, scalar|null> $params  flow parameter substitutions forwarded to the movement engine
     *
     * @psalm-param QuantityStateBatch<TSubject> $batch
     *
     * @return QuantityStateBatch the batch with updated movement results for each item
     *
     * @psalm-return QuantityStateBatch<TSubject>
     */
    public function execute(
        QuantityStateBatch $batch,
        SlotSpace $space,
        string | Flow $cascade,
        array $context = [],
        array $params = [],
    ): QuantityStateBatch {
        foreach ($batch->items() as $item) {
            $item->setMovementResult(
                $this->engine->execute(
                    $item->inventory,
                    $space,
                    $cascade,
                    $item->quantity,
                    // The batch knows which subject each item belongs to and labels its results
                    // with it, so the policies deciding that item's movement must see it too.
                    // Without it a per-subject rule — an allocation cap, a per-SKU backorder
                    // limit — silently behaves as though no subject were set, and only in batch
                    // mode, which is the mode such a rule exists for.
                    $item->subject,
                    appContext: $context,
                    params: $params,
                ),
            );
        }

        return $batch;
    }
}
