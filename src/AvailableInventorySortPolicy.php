<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

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

    private function available(CascadeContext $ctx, MovementEdge $edge): int|float
    {
        if ($edge->from->isNil()) {
            return INF;
        }

        return $ctx->inventory->get($edge->from);
    }
}
