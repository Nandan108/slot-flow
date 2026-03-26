<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\FlowContext;

/**
 * Chooses explicit edge allocations for one flow step.
 *
 * @api
 */
interface AllocationPolicyInterface
{
    /**
     * Return allocation decisions for the current step context.
     *
     * @return list<AllocationDecision>
     */
    public function allocate(FlowContext $ctx): array;
}
