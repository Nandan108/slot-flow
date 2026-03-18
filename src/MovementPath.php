<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class MovementPath
{
    /** @var array<MovementEdge> */
    private array $edges = [];

    public function __construct(MovementEdge ...$edges)
    {
        $this->edges = $edges;
    }

    public static function fromEdges(MovementEdge ...$edges): self
    {
        return new self(...$edges);
    }

    /** @return array<MovementEdge> */
    public function edges(): array
    {
        return $this->edges;
    }

    public function withEdge(MovementEdge $edge): self
    {
        $clone = clone $this;
        $clone->edges[] = $edge;

        return $clone;
    }

    public function reverse(bool $flipEdges = true): self
    {
        $clone = clone $this;
        $clone->edges = array_reverse($clone->edges, true);

        if ($flipEdges) {
            foreach ($clone->edges as $i => $edge) {
                $clone->edges[$i] = $edge->flip();
            }
        }

        return $clone;
    }

    /**
     * Orders the edges by the specified dimension of the source slot.
     *
     * @param non-empty-string       $dimension     the dimension to order by
     * @param list<non-empty-string> $orderedValues the values in the desired order
     */
    public function orderBySource(string $dimension, array $orderedValues): self
    {
        $clone = clone $this;

        $rankByValue = array_flip($orderedValues);

        usort(
            $clone->edges,
            static function (MovementEdge $a, MovementEdge $b) use ($dimension, $rankByValue): int {
                $aFrom = $a->from?->dimension($dimension);
                $bFrom = $b->from?->dimension($dimension);

                $aRank = null !== $aFrom && array_key_exists($aFrom, $rankByValue)
                    ? $rankByValue[$aFrom]
                    : PHP_INT_MAX;

                $bRank = null !== $bFrom && array_key_exists($bFrom, $rankByValue)
                    ? $rankByValue[$bFrom]
                    : PHP_INT_MAX;

                return $aRank <=> $bRank;
            },
        );

        return $clone;
    }
}
