<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Policies;

use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\FlowContext;

/**
 * Sorts edges in descending order of available quantity at the source slot.
 * Edges with a nil source are ranked lowest, treating creation-like sources
 * as a fallback behind concrete quantity whenever they appear in the same list.
 *
 * @api
 */
class AvailableQuantitySortPolicy implements EdgeOrderingPolicyInterface
{
    #[\Override]
    public function orderEdges(FlowContext $ctx): array
    {
        $edges = $ctx->edges;

        usort($edges, function (MovementEdge $left, MovementEdge $right) use ($ctx): int {
            return $this->available($ctx, $right) <=> $this->available($ctx, $left);
        });

        return $edges;
    }

    private function available(FlowContext $ctx, MovementEdge $edge): int | float
    {
        if ($edge->from->isNil()) {
            return -INF;
        }

        return $ctx->inventory->get($edge->from);
    }
}
