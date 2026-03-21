<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

interface QttyConstraintPolicyInterface
{
    public function constraint(MovementEdge $edge, CascadeContext $ctx): int | float;
}
