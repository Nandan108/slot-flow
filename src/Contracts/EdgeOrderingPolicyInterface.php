<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\FlowContext;

/**
 * Reorders candidate edges for one cascade step.
 *
 * @api
 */
interface EdgeOrderingPolicyInterface extends PolicyInterface
{
    /**
     * Return the candidate edges in preferred execution order.
     *
     * @return list<MovementEdge>
     */
    public function orderEdges(FlowContext $ctx): array;
}
