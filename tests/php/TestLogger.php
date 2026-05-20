<?php

namespace SilverstripeLtd\AiTranslate\Tests;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Captures log records for controller assertions.
 */
class TestLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<mixed>}>
     */
    public array $records = [];

    /**
     * Stores each emitted log record in memory.
     */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
