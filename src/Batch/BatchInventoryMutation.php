<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Batch;

use Nandan108\SlotFlow\Slot;

/**
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
