<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\CascadeContext;

/**
 * @api
 */
interface QttyConstraintPolicyInterface
{
    public function constraint(MovementEdge $edge, CascadeContext $ctx): int | float;
}
