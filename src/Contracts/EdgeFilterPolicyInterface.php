<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\CascadeContext;

/**
 * Removes edges from a step before ordering and allocation.
 *
 * @api
 */
interface EdgeFilterPolicyInterface
{
    /**
     * Return the subset of edges that should remain available.
     *
     * @return list<MovementEdge>
     */
    public function filterEdges(CascadeContext $ctx): array;
}
