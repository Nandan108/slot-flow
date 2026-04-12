<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Results;

use Nandan108\SlotFlow\MovementEdge;

/**
 * A representation of one movement event, from one slot to another, with all
 * the relevant details for ledgering and reporting.
 *
 * InitialFrom quantity MUST be provided for non-nil sources.
 * InitialTo quantity MUST be provided for non-nil sinks.
 */
final class LedgerEntry
{
    /**
     * Create one ledger-friendly movement record.
     *
     * @internal instances are created by SlotFlow's controlled movement/ledger pipeline
     *
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly MovementEdge $edge,
        public readonly int | float $quantity,
        public readonly int | float | null $initialFrom,
        public readonly int | float | null $initialTo,
        public readonly array $context = [],
    ) {
        // check that for non-nil sources/sinks, the initial quantity is provided
        if (!$edge->from->isNil() && null === $initialFrom) {
            throw new \InvalidArgumentException('initialFrom must be provided for non-nil sources');
        }
        if (!$edge->to->isNil() && null === $initialTo) {
            throw new \InvalidArgumentException('initialTo must be provided for non-nil sinks');
        }
    }

    /**
     * Return the source quantity after this ledgered movement, or null for nil sources.
     *
     * @api
     */
    public function finalFrom(): int | float | null
    {
        /** @psalm-suppress InvalidOperand */
        return null === $this->initialFrom
            ? null
            : $this->initialFrom - $this->quantity;
    }

    /**
     * Return the destination quantity after this ledgered movement, or null for nil sinks.
     *
     * @api
     */
    public function finalTo(): int | float | null
    {
        /** @psalm-suppress InvalidOperand */
        return null === $this->initialTo
            ? null
            : $this->initialTo + $this->quantity;
    }
}
