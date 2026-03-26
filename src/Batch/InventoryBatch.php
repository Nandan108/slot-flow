<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Batch;

/**
 * Backward-compatible alias for QuantityStateBatch.
 *
 * @template TSubject
 *
 * @template-extends QuantityStateBatch<TSubject>
 *
 * @deprecated use QuantityStateBatch instead
 *
 * @api
 */
class InventoryBatch extends QuantityStateBatch
{
    /**
     * @deprecated use deltas() instead
     *
     * @return list<BatchQuantityStateDelta<TSubject>>
     */
    public function mutations(): array
    {
        return $this->deltas();
    }
}
