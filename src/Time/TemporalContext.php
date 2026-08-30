<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Time;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\MovementEdge;

/**
 * The temporal configuration a slot space is expanded along: a time axis, the resolver that gives
 * each movement edge a duration, and the calendar that may delay when an edge departs.
 *
 * This is the whole of what the timed layer needs from a slot space, gathered into one value so a
 * space carries a single temporal dependency rather than three, and so the raw-callable
 * normalization lives in one place instead of once per consumer.
 *
 * Instances are immutable; the `with*` methods return copies.
 *
 * @api
 */
final class TemporalContext
{
    /**
     * Create one temporal context.
     */
    public function __construct(
        public readonly ?TimeAxis $axis = null,
        public readonly ?TimedDurationResolverInterface $durationResolver = null,
        public readonly ?DispatchCalendarInterface $dispatchCalendar = null,
    ) {
    }

    /**
     * Build a context from raw inputs, normalizing callables into their interface wrappers.
     *
     * @throws SlotFlowInvalidArgumentException if a resolver is neither a Closure nor the interface
     */
    public static function of(
        ?TimeAxis $axis = null,
        TimedDurationResolverInterface | \Closure | null $durationResolver = null,
        DispatchCalendarInterface | callable | null $dispatchCalendar = null,
    ): self {
        return new self(
            $axis,
            self::normalizeDurationResolver($durationResolver),
            self::normalizeDispatchCalendar($dispatchCalendar),
        );
    }

    /**
     * Return a copy carrying the given duration resolver.
     */
    public function withDurationResolver(mixed $durationResolver): self
    {
        return new self($this->axis, self::normalizeDurationResolver($durationResolver), $this->dispatchCalendar);
    }

    /**
     * Return a copy carrying the given dispatch calendar.
     */
    public function withDispatchCalendar(DispatchCalendarInterface | callable | null $dispatchCalendar): self
    {
        return new self($this->axis, $this->durationResolver, self::normalizeDispatchCalendar($dispatchCalendar));
    }

    /**
     * Return a copy carrying the given time axis.
     */
    public function withAxis(?TimeAxis $axis): self
    {
        return new self($axis, $this->durationResolver, $this->dispatchCalendar);
    }

    /**
     * Normalize a raw duration resolver into the interface, wrapping a Closure if needed.
     *
     * A wrapped Closure is validated at call time rather than declaration time, because a Closure
     * carries no return type the wrapper could check up front.
     *
     * @throws SlotFlowInvalidArgumentException if the input is neither a Closure nor the interface
     */
    public static function normalizeDurationResolver(mixed $durationResolver): ?TimedDurationResolverInterface
    {
        /** @psalm-suppress DocblockTypeContradiction */
        return match (true) {
            null === $durationResolver                                  => null,
            $durationResolver instanceof TimedDurationResolverInterface => $durationResolver,
            $durationResolver instanceof \Closure                       => new class($durationResolver) implements TimedDurationResolverInterface {
                public function __construct(private readonly \Closure $resolver)
                {
                }

                #[\Override]
                public function resolve(MovementEdge $edge, TimedDurationContext $context): int | string
                {
                    /** @psalm-var mixed $duration */
                    $duration = ($this->resolver)($edge, $context);

                    if (is_int($duration) || is_string($duration)) {
                        return $duration;
                    }

                    throw new SlotFlowInvalidArgumentException(
                        'Timed movement edge duration must be an int or time expression string.',
                        ['edge' => (string) $edge, 'duration' => $duration],
                    );
                }
            },
            default => throw new SlotFlowInvalidArgumentException(
                'Timed duration resolver must be a Closure or TimedDurationResolverInterface instance.',
                ['duration_resolver' => $durationResolver],
            ),
        };
    }

    /**
     * Normalize a raw dispatch calendar into the interface, wrapping a callable if needed.
     */
    public static function normalizeDispatchCalendar(
        DispatchCalendarInterface | callable | null $dispatchCalendar,
    ): ?DispatchCalendarInterface {
        return match (true) {
            null === $dispatchCalendar                             => null,
            $dispatchCalendar instanceof DispatchCalendarInterface => $dispatchCalendar,
            default                                                => new class(\Closure::fromCallable($dispatchCalendar)) implements DispatchCalendarInterface {
                public function __construct(private readonly \Closure $resolver)
                {
                }

                #[\Override]
                public function dispatchTime(MovementEdge $edge, TimedDurationContext $context): int
                {
                    /** @psalm-var mixed $dispatchTime */
                    $dispatchTime = ($this->resolver)($edge, $context);

                    if (is_int($dispatchTime)) {
                        return $dispatchTime;
                    }

                    throw new SlotFlowInvalidArgumentException(
                        'Dispatch calendar must resolve to an integer time index.',
                        ['edge' => (string) $edge, 'dispatch_time' => $dispatchTime],
                    );
                }
            },
        };
    }
}
