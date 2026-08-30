<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Time;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Slot;
use Nandan108\SlotFlow\SlotSpace;

/**
 * Time-expanded view of a base SlotSpace.
 *
 * @experimental The timed and demand-scheduling layer is unproven against a real workload:
 *               tested and documented, but not yet validated by a production consumer, so
 *               its shape is expected to change once one exists. Pin an exact version if
 *               you build on it. The execution engine carries no such caveat.
 *
 * @api
 */
final class TimedSlotSpace
{
    /** @var array<string, TimedSlot> */
    private array $slots = [];
    private readonly ?TimedDurationResolverInterface $durationResolver;
    private readonly ?DispatchCalendarInterface $dispatchCalendar;

    /**
     * Create one timed view over a base slot space and a discrete time axis.
     */
    public function __construct(
        public readonly SlotSpace $baseSpace,
        public readonly TimeAxis $axis,
        ?callable $durationResolver = null,
        DispatchCalendarInterface | callable | null $dispatchCalendar = null,
    ) {
        $this->durationResolver = TemporalContext::normalizeDurationResolver(
            null === $durationResolver ? null : \Closure::fromCallable($durationResolver),
        );
        $this->dispatchCalendar = TemporalContext::normalizeDispatchCalendar($dispatchCalendar);
    }

    /**
     * Build a timed view over one base slot space, defaulting to the space's declared time axis.
     */
    public static function fromBaseSpace(
        SlotSpace $baseSpace,
        ?TimeAxis $axis = null,
        ?callable $durationResolver = null,
        DispatchCalendarInterface | callable | null $dispatchCalendar = null,
    ): self {
        $axis ??= $baseSpace->timeAxis;
        if (null === $durationResolver) {
            $defaultDurationResolver = $baseSpace->getDurationResolver();
            $durationResolver = null === $defaultDurationResolver ? null : $defaultDurationResolver->resolve(...);
        }
        $dispatchCalendar ??= $baseSpace->getDispatchCalendar();

        if (null === $axis) {
            throw new SlotFlowInvalidArgumentException(
                'Timed slot space requires a TimeAxis, either passed explicitly or declared on the base SlotSpace.',
                [],
            );
        }

        return new self($baseSpace, $axis, $durationResolver, $dispatchCalendar);
    }

    /**
     * Expand a base quantity state into this timed slot space at one origin time.
     */
    public function timedQuantityState(QuantityState $state, \DateTimeImmutable | int | string $time = 0): TimedQuantityState
    {
        return TimedQuantityState::fromQuantityState($this, $state, $time);
    }

    /**
     * Resolve one timed slot from either `(slot, time)` input or a serialized `slot@time` key.
     */
    public function slot(Slot | string | null $slot, \DateTimeImmutable | int | string | null $time = null): TimedSlot
    {
        if (is_string($slot) && str_contains($slot, '@')) {
            $parts = explode('@', $slot, 2);
            if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
                throw new SlotFlowInvalidArgumentException(
                    'Invalid timed slot key; expected the form slot@time.',
                    ['slot' => $slot],
                );
            }

            [$slotKey, $timeKey] = $parts;
            if (null !== $time) {
                throw new SlotFlowInvalidArgumentException(
                    'Timed slot keys already include a time suffix; do not also pass a separate time.',
                    ['slot' => $slot, 'time' => $time],
                );
            }

            $slot = $this->baseSpace->slot($slotKey);
            $time = $timeKey;
        }

        if (null === $time) {
            throw new SlotFlowInvalidArgumentException(
                'Timed slots require an explicit time unless the serialized key already contains @time.',
                ['slot' => $slot, 'time' => $time],
            );
        }

        $resolvedSlot = $slot;
        if (!$resolvedSlot instanceof Slot) {
            if (!is_string($resolvedSlot) || '' === $resolvedSlot) {
                throw new SlotFlowInvalidArgumentException(
                    'Timed slots require a concrete base slot key or Slot instance.',
                    ['slot' => $resolvedSlot],
                );
            }

            $resolvedSlot = $this->baseSpace->slot($resolvedSlot);
        }
        $timeIndex = $this->axis->parse($time);
        if (!$this->axis->contains($timeIndex)) {
            throw new SlotFlowInvalidArgumentException(
                'Timed slot lies outside the configured time horizon.',
                ['slot' => $resolvedSlot->key, 'time' => $timeIndex, 'horizon' => $this->axis->horizon],
            );
        }

        $timeKey = $this->axis->key($timeIndex);
        $key = $resolvedSlot->key.'@'.$timeKey;

        return $this->slots[$key] ??= new TimedSlot(
            slot: $resolvedSlot,
            timeIndex: $timeIndex,
            timeKey: $timeKey,
            space: $this,
        );
    }

    /**
     * Expand one timed slot into the timed holdover edge plus any reachable timed movement edges.
     *
     * Return holdover and duration-expanded movement edges from one timed slot.
     *
     * @return list<TimedMovementEdge>
     */
    public function getEdgesFrom(TimedSlot $from): array
    {
        $edges = [];
        $baseEdges = $from->slot->isNil()
            ? array_map(
                fn (Slot $to): MovementEdge => new MovementEdge($from->slot, $to),
                $this->baseSpace->matchPartial([]),
            )
            : $from->slot->outgoingEdges();

        if ($from->timeIndex < $this->axis->horizon) {
            $edges[] = new TimedMovementEdge(
                from: $from,
                to: $from->at($from->timeIndex + 1),
                baseEdge: null,
                label: 'hold',
                attributes: ['duration' => 1, 'timed-kind' => 'holdover'],
            );
        }

        foreach ($baseEdges as $edge) {
            $dispatchTime = $this->dispatchTime($edge, $from);
            $duration = $this->duration($edge, $from, $dispatchTime);
            $arrival = $dispatchTime + $duration;
            if ($arrival > $this->axis->horizon) {
                continue;
            }

            $edges[] = new TimedMovementEdge(
                from: $from,
                to: $this->slot($edge->to, $arrival),
                baseEdge: $edge,
                label: $edge->label,
                attributes: [
                    'duration'      => $duration,
                    'dispatch-time' => $dispatchTime,
                    'wait-duration' => $dispatchTime - $from->timeIndex,
                    'timed-kind'    => 'movement',
                ] + $edge->attributes,
            );
        }

        return $edges;
    }

    /**
     * Return every timed slot in the expanded space for one resolved time index.
     *
     * @return list<TimedSlot>
     */
    public function slotsAt(\DateTimeImmutable | int | string $time): array
    {
        $timeIndex = $this->axis->parse($time);

        return array_map(
            fn (Slot $slot): TimedSlot => $this->slot($slot, $timeIndex),
            $this->baseSpace->matchPartial([]),
        );
    }

    /**
     * Resolve one base-edge duration into canonical bucket count.
     */
    private function duration(MovementEdge $edge, TimedSlot $from, int $dispatchTime): int
    {
        $context = new TimedDurationContext($this, $this->axis, $from, $edge, $dispatchTime);
        $duration = null !== $this->durationResolver
            ? $this->durationResolver->resolve($edge, $context)
            : $this->defaultDurationValue($edge);

        return is_int($duration) ? $duration : $this->axis->parse($duration);
    }

    private function dispatchTime(MovementEdge $edge, TimedSlot $from): int
    {
        $context = new TimedDurationContext($this, $this->axis, $from, $edge, $from->timeIndex);
        $dispatchTime = null !== $this->dispatchCalendar
            ? $this->dispatchCalendar->dispatchTime($edge, $context)
            : $from->timeIndex;

        if ($dispatchTime < $from->timeIndex) {
            throw new SlotFlowInvalidArgumentException(
                'Dispatch calendar cannot move a departure earlier than the current timed slot.',
                ['edge' => (string) $edge, 'dispatch_time' => $dispatchTime, 'from_time' => $from->timeIndex],
            );
        }

        return $dispatchTime;
    }

    private function defaultDurationValue(MovementEdge $edge): int | string
    {
        /** @psalm-var mixed $duration */
        $duration = $edge->attributes['duration'] ?? $edge->from->attributes['duration'] ?? 0;

        if (is_int($duration) || is_string($duration)) {
            return $duration;
        }

        throw new SlotFlowInvalidArgumentException(
            'Timed movement edge duration must be an int or time expression string.',
            ['edge' => (string) $edge, 'duration' => $duration],
        );
    }
}
