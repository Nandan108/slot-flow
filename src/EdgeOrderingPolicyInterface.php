<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

interface EdgeOrderingPolicyInterface
{
    /**
     * @return list<MovementEdge>
     */
    public function orderEdges(CascadeContext $ctx): array;
}
