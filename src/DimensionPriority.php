<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class DimensionPriority implements EdgeOrderingPolicyInterface
{
    /**
     * @param array<non-empty-string, list<non-empty-string>> $priorities
     */
    public function __construct(
        private readonly array $priorities,
    ) {
    }

    #[\Override]
    public function orderEdges(CascadeContext $ctx): array
    {
        $edges = $ctx->edges;

        usort($edges, function (MovementEdge $left, MovementEdge $right): int {
            foreach ($this->priorities as $dimension => $orderedValues) {
                $rankByValue = array_flip($orderedValues);
                $leftValue = $left->from->dimension($dimension);
                $rightValue = $right->from->dimension($dimension);

                $leftRank = is_string($leftValue) && array_key_exists($leftValue, $rankByValue)
                    ? $rankByValue[$leftValue]
                    : PHP_INT_MAX;
                $rightRank = is_string($rightValue) && array_key_exists($rightValue, $rankByValue)
                    ? $rankByValue[$rightValue]
                    : PHP_INT_MAX;

                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }
            }

            return 0;
        });

        return $edges;
    }
}
