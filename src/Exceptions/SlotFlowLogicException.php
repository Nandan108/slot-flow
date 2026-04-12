<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Exceptions;

/**
 * Raised when SlotFlow detects an invalid internal or object state transition.
 *
 * @api
 */
class SlotFlowLogicException extends \LogicException implements SlotFlowExceptionInterface
{
    /**
     * Create one SlotFlow logic exception with optional structured debug context.
     *
     * @param array<string, mixed> $debugContext
     */
    public function __construct(
        string $message = '',
        private readonly array $debugContext = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Return structured details about the invalid state that caused the exception.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function debugContext(): array
    {
        return $this->debugContext;
    }
}
