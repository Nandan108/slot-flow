<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Exceptions;

/**
 * Raised when a SlotFlow API is called with invalid or inconsistent arguments.
 *
 * @api
 */
class SlotFlowInvalidArgumentException extends \InvalidArgumentException implements SlotFlowExceptionInterface
{
    /** @param array<string, mixed> $debugContext */
    public function __construct(
        string $message = '',
        private readonly array $debugContext = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function debugContext(): array
    {
        return $this->debugContext;
    }
}
