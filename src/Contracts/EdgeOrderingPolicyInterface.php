<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\CascadeContext;

/**
 * @api
 */
interface EdgeOrderingPolicyInterface
{
    /**
     * @return list<MovementEdge>
     */
    public function orderEdges(CascadeContext $ctx): array;
}
