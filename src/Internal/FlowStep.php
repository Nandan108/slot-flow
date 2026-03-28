<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Internal;

use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeFilterPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\FlowContext;
use Nandan108\SlotFlow\SlotSpace;

/**
 * @psalm-import-type TSlotPattern from SlotSpace
 *
 * @internal
 */
final class FlowStep
{
    /**
     * @param list<(callable(FlowContext): list<MovementEdge>)|EdgeOrderingPolicyInterface>     $orderingPolicies
     * @param list<(callable(FlowContext): list<MovementEdge>)|EdgeFilterPolicyInterface>       $filterPolicies
     * @param list<(callable(MovementEdge, FlowContext): mixed)|QttyConstraintPolicyInterface>  $quantityConstraintPolicies
     * @param list<(callable(FlowContext): list<AllocationDecision>)|AllocationPolicyInterface> $allocationPolicies
     * @param list<non-empty-string>|null                                                       $edgeLabels
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
