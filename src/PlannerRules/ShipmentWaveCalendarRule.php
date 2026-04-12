<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\PlannerRules;

use Nandan108\SlotFlow\Contracts\ShipmentCalendarRuleInterface;
use Nandan108\SlotFlow\DemandReleaseContext;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Results\DemandShipmentLine;
use Nandan108\SlotFlow\Results\ScheduledStep;

/**
 * Delay shipment release until the next recurring shipment wave.
 *
 * This is useful for modeled edges whose completed movement can only hand over
 * to a carrier or locker on fixed daily or periodic waves.
 *
 * @api
 */
final class ShipmentWaveCalendarRule implements ShipmentCalendarRuleInterface
{
    /**
     * Create one recurring shipment-wave rule.
     *
     * @throws SlotFlowInvalidArgumentException
     */
    public function __construct(
        public readonly int $interval,
        public readonly int $offset = 0,
    ) {
        if ($interval <= 0) {
            throw new SlotFlowInvalidArgumentException('Shipment wave interval must be greater than zero.', ['interval' => $interval]);
        }

        if ($offset < 0 || $offset >= $interval) {
            throw new SlotFlowInvalidArgumentException(
                'Shipment wave offset must be within the interval bounds.',
                ['interval' => $interval, 'offset' => $offset],
            );
        }
    }

    /**
     * Return the next release time aligned to this rule's recurring wave.
     */
    #[\Override]
    public function releaseTime(
        DemandReleaseContext $context,
        DemandShipmentLine $line,
        ScheduledStep $step,
        int $earliestReleaseTime,
    ): int {
        $remainder = $earliestReleaseTime % $this->interval;
        if ($remainder <= $this->offset) {
            return $earliestReleaseTime + ($this->offset - $remainder);
        }

        return $earliestReleaseTime + ($this->interval - ($remainder - $this->offset));
    }
}
