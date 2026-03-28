<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Time;

use Nandan108\SlotFlow\MovementEdge;

/**
 * Resolves movement duration during timed space expansion.
 *
 * @api
 */
interface TimedDurationResolverInterface
{
    public function resolve(MovementEdge $edge, TimedDurationContext $context): int | string;
}
