<?php

namespace SilverstripeLtd\AiTranslate\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Represents provider errors with transient/blocking flags.
 */
class AIProviderException extends RuntimeException
{
    private bool $transient;
    private bool $blocking;

    /**
     * Create a provider exception with transient and blocking metadata.
     */
    public function __construct(
        string $message,
        bool $transient = false,
        bool $blocking = false,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->transient = $transient;
        $this->blocking = $blocking;
    }

    /**
     * Determine whether the exception is transient.
     */
    public function isTransient(): bool
    {
        return $this->transient;
    }

    /**
     * Determine whether the exception should block processing.
     */
    public function isBlocking(): bool
    {
        return $this->blocking;
    }
}
