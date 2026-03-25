<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Batch;

use Nandan108\SlotFlow\Slot;

/**
 * Inventory mutation paired with the batch subject it belongs to.
 *
 * @template TSubject
 *
 * @api
 */
final class BatchInventoryMutation
{
    /**
     * @param TSubject $subject
     */
    public function __construct(
        public readonly mixed $subject,
        public readonly Slot $slot,
        public readonly int | float $delta,
    ) {
    }
}
