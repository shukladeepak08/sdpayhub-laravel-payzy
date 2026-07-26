<?php

declare(strict_types=1);

namespace Sdpayhub\Payzy\Exceptions;

use Exception;
use Throwable;

/**
 * Base exception for all Payzy errors.
 *
 * Consumers may catch this broadly, or catch specific subclasses narrowly.
 */
class PayzyException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
