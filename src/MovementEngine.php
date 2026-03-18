<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Contracts\Constraint;

/**
 * @template TQtty of int|float
 */
final class MovementEngine
{
    /**
     * Summary of execute.
     *
     * @param TQtty                   $quantity
     * @param list<Constraint<TQtty>> $constraints
     *
     * @return MovementResult<TQtty>
     */
    public function execute(
        Inventory $inventory,
        MovementPath $path,
        int | float $quantity,
        array $constraints = [],
    ): MovementResult {
        $remaining = $quantity;

        /** @var list<MovementEvent<TQtty>> $events */
        $events = [];

        foreach ($path->edges() as $edge) {
            if ($remaining <= 0) {
                break;
            }

            $movable = $remaining;

            if (!$edge->from->isNil()) {
                $available = $inventory->get($edge->from);
                $movable = min($movable, $available);
            }
            /** @var TQtty $movable */
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

            $events[] = new MovementEvent(
                $edge,
                $movable,
                $edge->from->isNil() ? null : $inventory->get($edge->from),
                $edge->to->isNil() ? null : $inventory->get($edge->to),
            );

            if (!$edge->from->isNil()) {
                $inventory->add($edge->from, -$movable);
            }

            if (!$edge->to->isNil()) {
                $inventory->add($edge->to, $movable);
            }

            /** @psalm-suppress InvalidOperand, MixedOperand */
            $remaining -= $movable;
        }

        /** @psalm-suppress InvalidArgument */
        $result = new MovementResult($events, $remaining);

        /** @var MovementResult<TQtty> $result */
        return $result;
    }
}
