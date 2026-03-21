<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

interface EdgeFilterPolicyInterface
{
    /**
     * @return list<MovementEdge>
     */
    public function filterEdges(CascadeContext $ctx): array;
}
