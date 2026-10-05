<?php

namespace SilverstripeLtd\AiTranslate\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Represents an apply request that cannot proceed, tagged with a stable reason code.
 */
class TranslationApplyException extends RuntimeException
{
    public const REASON_DEFAULT_LOCALE = 'default-locale';
    public const REASON_NOT_LOCALISED = 'not-localised';
    public const REASON_DEFAULT_LOCALE_MISSING = 'default-locale-missing';
    public const REASON_RECORD_NOT_FOUND = 'record-not-found';
    public const REASON_PERMISSION_DENIED = 'permission-denied';

    private string $reason;

    /**
     * Creates an apply exception with a machine-readable reason and a display message.
     */
    public function __construct(string $reason, string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->reason = $reason;
    }

    /**
     * Returns the stable reason code for the failure.
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
