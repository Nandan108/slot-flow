<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Policies;

use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\FlowContext;
use Nandan108\SlotFlow\SlotSpace;

/**
 * Orders edges by configured priority tiers on source-slot dimensions.
 *
 * @api
 */
final class DimensionPriority implements EdgeOrderingPolicyInterface
{
    /** @var array<non-empty-string, array<non-empty-string, int>> */
    private array $rankByDimensionValueCache = [];

    /**
     * @param array<non-empty-string, list<non-empty-string>> $priorities
     */
    public function __construct(
        private readonly array $priorities,
    ) {
    }

    /**
     * Build a rank map for each dimension, preserving pattern groups as priority tiers.
     *
     * Values matched by the same pattern share the same rank. This means a pattern
     * like 'wh*' behaves as one priority tier, rather than imposing an arbitrary
     * order between all concrete values it matches.
     *
     * @return array<non-empty-string, array<non-empty-string, int>>
     */
    private function getRankByDimensionValue(SlotSpace $space): array
    {
        if ([] !== $this->rankByDimensionValueCache) {
            return $this->rankByDimensionValueCache;
        }

        foreach ($this->priorities as $dimension => $patterns) {
            $rankByValue = [];
            foreach ($patterns as $rank => $pattern) {
                $values = $space->codec->matchDimensionValues($dimension, $pattern);

                foreach ($values as $value) {
                    $rankByValue[$value] ??= $rank;
                }
            }

            $this->rankByDimensionValueCache[$dimension] = $rankByValue;
        }

        return $this->rankByDimensionValueCache;
    }

    /**
     * Order edges based on the defined dimension priorities.
     * Edges are sorted by the rank of their 'from' slot's dimension values.
     * If a dimension value is not found in the priority list, it is assigned a default rank of PHP_INT_MAX,
     * ensuring it is treated as lowest priority.
     */
    #[\Override]
    public function orderEdges(FlowContext $ctx): array
    {
        $edges = $ctx->edges;
        $rankByDimensionValue = $this->getRankByDimensionValue($ctx->space);

        usort($edges, function (MovementEdge $left, MovementEdge $right) use ($rankByDimensionValue): int {
            foreach ($rankByDimensionValue as $dimension => $rankByValue) {
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
