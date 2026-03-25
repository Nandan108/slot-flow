<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\CascadeContext;

/**
 * @api
 */
interface AllocationPolicyInterface
{
    /**
     * @return list<AllocationDecision>
     */
    public function allocate(CascadeContext $ctx): array;
}
