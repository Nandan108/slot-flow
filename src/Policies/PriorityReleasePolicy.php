<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Policies;

use Nandan108\SlotFlow\Contracts\DemandReleasePolicyInterface;
use Nandan108\SlotFlow\DemandReleaseContext;
use Nandan108\SlotFlow\Results\DemandShipmentLine;

/**
 * Ships high-priority lines as they become ready and lower-priority lines on line completion.
 *
 * @api
 */
final class PriorityReleasePolicy implements DemandReleasePolicyInterface
{
    /**
     * Create one priority-based release policy.
     *
     * @param array<string, int> $priorityBySubject lower numbers mean higher priority
     */
    public function __construct(
        /** Lower numeric values mean higher release priority for the subject. */
        private readonly array $priorityBySubject = [],
        /** Maximum priority value that is allowed to ship immediately on arrival. */
        private readonly int $immediatePriorityThreshold = 0,
    ) {
    }

    /**
     * Release high-priority lines on arrival and lower-priority lines on line completion.
     *
     * @return list<DemandShipmentLine>
     */
    #[\Override]
    public function release(DemandReleaseContext $context): array
    {
        /** @var list<DemandShipmentLine> $shipmentLines */
        $shipmentLines = [];

        foreach ($context->lineSchedules as $lineSchedule) {
            $priority = $this->priorityBySubject[$lineSchedule->subjectKey] ?? PHP_INT_MAX;
            $available = $context->availableQuantityForLine($lineSchedule);
            if ($available <= 0) {
                continue;
            }

            if ($priority <= $this->immediatePriorityThreshold) {
                $shipmentLines[] = new DemandShipmentLine(
                    subjectKey: $lineSchedule->subjectKey,
                    quantity: $available,
                    lineSchedule: $lineSchedule,
                );
                continue;
            }

            if (!$lineSchedule->isComplete() || $available < $context->remainingQuantityForLine($lineSchedule)) {
                continue;
            }

            $shipmentLines[] = new DemandShipmentLine(
                subjectKey: $lineSchedule->subjectKey,
                quantity: $available,
                lineSchedule: $lineSchedule,
            );
        }

        usort(
            $shipmentLines,
            fn (DemandShipmentLine $left, DemandShipmentLine $right): int => ($this->priorityBySubject[$left->subjectKey] ?? PHP_INT_MAX)
                <=>
                ($this->priorityBySubject[$right->subjectKey] ?? PHP_INT_MAX),
        );

        return $shipmentLines;
    }
}
