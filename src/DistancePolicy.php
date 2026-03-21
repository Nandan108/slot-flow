<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class DistancePolicy implements EdgeFilterPolicyInterface, EdgeOrderingPolicyInterface
{
    public function __construct(
        private readonly int | float | null $max = null,
    ) {
    }

    #[\Override]
    public function filterEdges(CascadeContext $ctx): array
    {
        if (null === $this->max) {
            return $ctx->edges;
        }

        return array_values(array_filter(
            $ctx->edges,
            fn (MovementEdge $edge): bool => $this->distanceFor($edge, $ctx) <= $this->max,
        ));
    }

    #[\Override]
    public function orderEdges(CascadeContext $ctx): array
    {
        $edges = $ctx->edges;

        usort(
            $edges,
            fn (MovementEdge $left, MovementEdge $right): int => $this->distanceFor($left, $ctx) <=> $this->distanceFor($right, $ctx),
        );

        return $edges;
    }

    private function distanceFor(MovementEdge $edge, CascadeContext $ctx): int | float
    {
        $distance = $ctx->context['distance'] ?? null;

        if (is_callable($distance)) {
            /** @psalm-var mixed */
            $value = $distance($edge, $ctx);

            return is_int($value) || is_float($value) ? $value : INF;
        }

        if (is_array($distance)) {
            $key = $edge->from->key.'->'.$edge->to->key;
            /** @psalm-var mixed */
            $value = $distance[$key] ?? INF;

            return is_int($value) || is_float($value) ? $value : INF;
        }

        return 0;
    }
}
