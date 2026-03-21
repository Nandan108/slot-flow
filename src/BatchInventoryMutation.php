<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * @template TSubject
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
