<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

interface Policy
{
    /**
     * Summary of resolve.
     */
    public function resolve(BatchItem $item): MovementPlan;
}
