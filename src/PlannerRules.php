<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Contracts\PlannerRuleInterface;
use Nandan108\SlotFlow\Contracts\ShipmentCalendarRuleInterface;
use Nandan108\SlotFlow\Contracts\ShipmentSplitRuleInterface;

/**
 * Utility helpers for planner-rule metadata stored on movement edges.
 *
 * @api
 */
final class PlannerRules
{
    /**
     * Merge planner rules into one edge metadata array and group them by type.
     *
     * @param array<array-key, mixed>                $attributes
     * @param array<array-key, PlannerRuleInterface> $rules
     *
     * @return array<array-key, mixed>
     */
    public static function merge(array $attributes, array $rules): array
    {
        return PolicyBuckets::mergeEdgeAttributes($attributes, $rules);
    }

    /**
     * @param array<array-key, mixed> $attributes
     *
     * @return list<PlannerRuleInterface>
     */
    public static function all(array $attributes): array
    {
        return PolicyBuckets::planner($attributes);
    }

    /**
     * @param array<array-key, mixed> $attributes
     *
     * @return list<ShipmentCalendarRuleInterface>
     */
    public static function shipmentCalendar(array $attributes): array
    {
        return PolicyBuckets::shipmentCalendar($attributes);
    }

    /**
     * @param array<array-key, mixed> $attributes
     *
     * @return list<ShipmentSplitRuleInterface>
     */
    public static function shipmentSplit(array $attributes): array
    {
        return PolicyBuckets::shipmentSplit($attributes);
    }
}
