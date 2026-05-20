<?php

namespace SilverstripeLtd\AiTranslate\Tests\Providers;

use SilverstripeLtd\AiTranslate\Providers\AbstractAIProvider;

/**
 * Minimal provider stub for factory tests.
 */
class StubProvider extends AbstractAIProvider
{
    /**
     * {@inheritDoc}
     */
    protected function performRequest(string $systemPrompt, string $userPrompt): array
    {
        return [
            'status' => 200,
            'body' => '',
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function extractResponseContent(string $body): string
    {
        return '';
    }

    /**
     * {@inheritDoc}
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    protected function getDefaultModel(): string
    {
        return 'test-model';
    }
}
