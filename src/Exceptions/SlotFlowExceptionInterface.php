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
    /** @return array<string, mixed> */
    public function debugContext(): array;
}
