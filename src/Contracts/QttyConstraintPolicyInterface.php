<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\CascadeContext;

/**
 * Caps how much quantity may move through one candidate edge.
 *
 * @api
 */
interface QttyConstraintPolicyInterface
{
    /**
     * Return the maximum movable quantity for the given edge in context.
     */
    public function constraint(MovementEdge $edge, CascadeContext $ctx): int | float;
}
