<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Batch;

/**
 * Backward-compatible alias for BatchQuantityStateDelta.
 *
 * @template TSubject
 *
 * @template-extends BatchQuantityStateDelta<TSubject>
 *
 * @deprecated use BatchQuantityStateDelta instead
 *
 * @api
 */
class BatchInventoryMutation extends BatchQuantityStateDelta
{
}
