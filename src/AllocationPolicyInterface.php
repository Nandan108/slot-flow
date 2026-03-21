<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

interface AllocationPolicyInterface
{
    /**
     * @return list<AllocationDecision>
     */
    public function allocate(CascadeContext $ctx): array;
}
