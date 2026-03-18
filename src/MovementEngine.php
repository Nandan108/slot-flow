<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class MovementEngine
{
    /**
     * Summary of execute.
     *
     * @param array<Constraint> $constraints
     */
    public function execute(
        Inventory $inventory,
        MovementPath $path,
        int $quantity,
        array $constraints = [],
    ): MovementResult {
        $remaining = $quantity;

        /** @var list<array{SlotKey, int}> $changes */
        $changes = [];
        /** @var list<MovementEvent> $events */
        $events = [];

        foreach ($path->edges() as $edge) {
            if ($remaining <= 0) {
                break;
            }

            $movable = $remaining;

            if ($edge->from) {
                $available = $inventory->get($edge->from);
                $movable = min($movable, $available);
            }

            foreach ($constraints as $constraint) {
                $movable = $constraint->limit(
                    $inventory,
                    $edge,
                    $movable,
                );
            }

            if ($movable <= 0) {
                continue;
            }

            $events[] = new MovementEvent($edge, $movable);

            if ($edge->from) {
                $inventory->add($edge->from, -$movable);
                $changes[] = [$edge->from, -$movable];
            }

            if ($edge->to) {
                $inventory->add($edge->to, $movable);
                $changes[] = [$edge->to, $movable];
            }

            $remaining -= $movable;
        }

        /** @var non-negative-int $remaining */
        $mutations = array_map(fn ($change) => new SlotMutation($change[0], $change[1]), $changes);

        return new MovementResult($mutations, $events, $remaining);
    }
}
