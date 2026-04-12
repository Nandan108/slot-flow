<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * Backward-compatible inventory-oriented alias for Flow.
 *
 * @deprecated use Flow instead
 *
 * @api
 */
class Cascade extends Flow
{
    /**
     * Copy one flow into a backward-compatible cascade instance.
     */
    #[\Override]
    public static function fromFlow(Flow $flow): static
    {
        /** @var static */
        return parent::fromFlow($flow);
    }
}
