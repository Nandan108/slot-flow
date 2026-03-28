<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\MovementSchedule;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Results\MovementEvent;
use Nandan108\SlotFlow\Results\ScheduledStep;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\Rules\SlotRule;
use Nandan108\SlotFlow\ScheduleRequest;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Solvers\EarliestArrivalSolver;
use Nandan108\SlotFlow\Time\TimeAxis;
use Nandan108\SlotFlow\Time\TimedDurationContext;

/**
 * Delivery-promise planning example with multiple supply subjects and transit slots.
 */
final class DeliveryPromiseExample
{
    public readonly SlotSpace $space;

    private const FLOW = 'promise-deliver';
    private const TARGET = 'cust._.P.sd';

    /** @var array<non-empty-string, non-empty-string> */
    public array $transitDurations = [
        'wh1-cust' => '2d',
        'wh2-wh1'  => '2d',
        'sup-wh1'  => '3d',
    ];

    /**
     * @param array<non-empty-string, non-empty-string> $durations
     *
     * @return array<non-empty-string, non-empty-string>
     */
    private function normalizeTransitDurations($durations)
    {
        $normalized = [];

        foreach ($durations as $fromTo => $override) {
            if (false !== strpos($fromTo, '-')) {
                /** @psalm-suppress PossiblyUndefinedArrayOffset */
                [$from, $to] = explode('-', $fromTo, 2);
                $normalized["$from.$to.*.*"] = $override;
            } else {
                $normalized[$fromTo] = $override;
            }
        }

        return $normalized;
    }

    /** @param array<non-empty-string, non-empty-string> $transitDurationOverrides */
    public function __construct(
        array $transitDurationOverrides = [],
    ) {
        // Intake duration override and normalize any "from-to" keys
        // to the full slot pattern with wildcards.
        $this->transitDurations = $this->normalizeTransitDurations([
            ...$this->transitDurations,
            ...$transitDurationOverrides,
        ]);

        $this->space = SlotSpace::defineTimed(
            dimensions: [
                'loc'   => ['sup', 'wh1', 'wh2', 'cust'],
                'dest'  => ['_', 'sup', 'wh1', 'wh2', 'cust'],
                'own'   => ['S', 'P'],
                'state' => ['fs', 'sd'],
            ],
            timeAxis: new TimeAxis(
                bucket: 'hour',
                horizon: 24 * 14,
                aliases: ['day' => 24, 'shift' => 8],
                humanKeyParts: ['d', 'h'], // for human-readable keys like "2d8h" instead of "h56"
            ),
        )->setDurationResolver(function (MovementEdge $edge, TimedDurationContext $context): int {
            $baseDuration = $edge->attributes['duration'] ?? $edge->from->attributes['duration'] ?? 0;

            is_int($baseDuration) || is_string($baseDuration)
                || throw new \LogicException('Expected edge or transit-slot duration metadata to be int|string.');

            $duration = $context->axis->parse($baseDuration);

            foreach ($this->transitDurations as $slotPattern => $offset) {
                if ($edge->from->matches($slotPattern)) {
                    $duration += $context->axis->parse($offset);
                }
            }

            return $duration;
        })->slotRules([
            SlotRule::allow('*'),
        ])->edgeRules([
            // Two purchased units already sit in wh2 and can be sent toward wh1 immediately.
            EdgeRule::allowLabeled('dispatch-wh2-to-wh1', 'wh2._.P.*', 'wh2.wh1.P.*'),
            EdgeRule::allowLabeled('transit-wh2-to-wh1', 'wh2.wh1.P.*', 'wh1._.P.*'),

            // Backordered supplier-owned units are released in four days.
            EdgeRule::allowLabeled('dispatch-supplier-to-wh1', 'sup._.S.fs', 'sup.wh1.S.sd', ['duration' => '4d']),
            // Then they spend three days in transit before arriving at wh1 (duration defined on transit slot).
            EdgeRule::allowLabeled('transit-supplier-to-wh1', 'sup.wh1.S.sd', 'wh1._.P.sd'),

            // At wh1, receiving and outbound dispatch each take one shift.
            EdgeRule::allowLabeled('receive-at-wh1', 'wh1._.P.sd', 'wh1._.P.fs', ['duration' => '1s']),
            EdgeRule::allowLabeled('dispatch-to-customer', 'wh1._.P.fs', 'wh1.cust.P.sd', ['duration' => '1s']),

            // Final-mile delivery itself is represented as a transit slot followed by a timed arrival.
            EdgeRule::allowLabeled('deliver-to-customer', 'wh1.cust.P.sd', self::TARGET),
        ])->flow(
            self::FLOW,
            static fn (Flow $flow) => $flow
                ->stepByLabeledEdges('dispatch-wh2-to-wh1', 'dispatch-supplier-to-wh1')
                ->stepByLabeledEdges('transit-wh2-to-wh1', 'transit-supplier-to-wh1')
                ->stepByLabeledEdges('receive-at-wh1')
                ->stepByLabeledEdges('dispatch-to-customer')
                ->stepByLabeledEdges('deliver-to-customer'),
        );
    }

    public function startingState(): QuantityState
    {
        return new QuantityState($this->space, [
            ['wh2._.P.fs', 2],
            ['sup._.S.fs', 3],
        ]);
    }

    public function plan(int | float $quantity): MovementSchedule
    {
        return (new EarliestArrivalSolver())->schedule(new ScheduleRequest(
            state: $this->startingState(),
            space: $this->space,
            flow: self::FLOW,
            quantity: $quantity,
            target: self::TARGET,
        ));
    }

    public function executeScheduledStep(QuantityState $state, ScheduledStep $step, int | float $quantity): MovementResult
    {
        $baseEdge = $step->edge->baseEdge;
        if (null === $baseEdge) {
            throw new \LogicException('Scheduled steps in this fixture must map to a base edge.');
        }

        $initialFrom = $state->get($baseEdge->from);
        $initialTo = $state->get($baseEdge->to);

        if (!$baseEdge->from->isNil()) {
            $state->add($baseEdge->from, -$quantity);
        }

        if (!$baseEdge->to->isNil()) {
            $state->add($baseEdge->to, $quantity);
        }

        return new MovementResult([
            new MovementEvent($baseEdge, $quantity, $initialFrom, $initialTo),
        ], 0);
    }
}
