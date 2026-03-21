<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * @psalm-import-type TSlotPattern from SlotSpace
 */
final class CascadeStep
{
    /**
     * @param list<callable|EdgeOrderingPolicyInterface>   $orderingPolicies
     * @param list<callable|EdgeFilterPolicyInterface>     $filterPolicies
     * @param list<callable|QttyConstraintPolicyInterface> $quantityConstraintPolicies
     * @param list<callable|AllocationPolicyInterface>     $allocationPolicies
     * @param list<non-empty-string>|null                  $edgeLabels
     *
     * @psalm-param TSlotPattern $from
     * @psalm-param TSlotPattern $to
     */
    public function __construct(
        public readonly string | array | null $from,
        public readonly string | array | null $to,
        public readonly ?array $edgeLabels = null,
        public array $orderingPolicies = [],
        public array $filterPolicies = [],
        public array $quantityConstraintPolicies = [],
        public array $allocationPolicies = [],
    ) {
    }
}
