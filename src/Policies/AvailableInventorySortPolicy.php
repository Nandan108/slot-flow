<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Policies;

use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\CascadeContext;

/**
 * Sorts edges in descending order of available inventory at the source slot.
 * Edges with a nil source are ranked lowest, treating creation-like sources
 * as a fallback behind concrete inventory whenever they appear in the same list.
 *
 * @api
 */
final class AvailableInventorySortPolicy implements EdgeOrderingPolicyInterface
{
    #[\Override]
    public function orderEdges(CascadeContext $ctx): array
    {
        $edges = $ctx->edges;

        usort($edges, function (MovementEdge $left, MovementEdge $right) use ($ctx): int {
            return $this->available($ctx, $right) <=> $this->available($ctx, $left);
        });

        return $edges;
    }

    private function available(CascadeContext $ctx, MovementEdge $edge): int | float
    {
        if ($edge->from->isNil()) {
            return -INF;
        }

        return $ctx->inventory->get($edge->from);
    }
}
