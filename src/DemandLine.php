<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * One requested subject quantity inside a multi-line demand.
 *
 * A demand line may optionally override the order-level scheduling defaults:
 *
 * - `flow`: use a different movement flow for this line than the one declared on
 *   the surrounding `DemandScheduleRequest`; useful when some SKUs follow a
 *   different sourcing or fulfillment route
 * - `target`: use a different destination slot than the request default; useful
 *   when lines in the same demand are promised toward different final states or
 *   destinations
 * - `params`: provide line-scoped flow parameters that are merged over the
 *   request-level params; useful when a shared flow template needs different
 *   concrete values per line, such as source lane, warehouse, or ownership
 *   bucket
 *
 * @api
 */
final class DemandLine
{
    /**
     * Create one demand line for one subject.
     *
     * @param mixed                      $subject  subject identifier or payload whose quantity is being requested
     * @param int|float                  $quantity requested quantity for this subject
     * @param Flow|non-empty-string|null $flow     optional flow override for this line
     * @param Slot|non-empty-string|null $target   optional target-slot override for this line
     * @param array<string, scalar|null> $params   optional line-scoped flow parameters
     */
    public function __construct(
        public readonly mixed $subject,
        public readonly int | float $quantity,
        public readonly Flow | string | null $flow = null,
        public readonly Slot | string | null $target = null,
        public readonly array $params = [],
    ) {
    }
}
