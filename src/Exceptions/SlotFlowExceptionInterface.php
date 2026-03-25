<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Exceptions;

/**
 * Marker interface for all exceptions thrown by the SlotFlow library.
 *
 * @api
 */
interface SlotFlowExceptionInterface extends \Throwable
{
    /**
     * Return structured details about the failing input or state, when available.
     *
     * @return array<string, mixed>
     */
    public function debugContext(): array;
}
