<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

/**
 * Marker interface for planner-level rules declared on movement edges.
 *
 * The route model may attach these rules to edges, while shipment planners
 * decide later which specialized rule categories are relevant to each phase
 * of order-level planning.
 *
 * @api
 */
interface PlannerRuleInterface extends PolicyInterface
{
}
