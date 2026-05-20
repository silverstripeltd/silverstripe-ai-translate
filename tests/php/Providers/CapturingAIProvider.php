<?php

namespace SilverstripeLtd\AiTranslate\Tests\Providers;

use SilverstripeLtd\AiTranslate\Providers\AbstractAIProvider;

/**
 * Provider stub that captures prompt inputs.
 */
class CapturingAIProvider extends AbstractAIProvider
{
    public ?string $lastSystemPrompt = null;
    public ?string $lastUserPrompt = null;

    private string $body;
    private int $status;

    /**
     * @param string $body Response body to return for success.
     * @param int $status HTTP status code for the response.
     */
    public function __construct(string $body = 'Translated', int $status = 200)
    {
        parent::__construct();
        $this->body = $body;
        $this->status = $status;
    }

    /**
     * {@inheritDoc}
     */
    protected function performRequest(string $systemPrompt, string $userPrompt): array
    {
        $this->lastSystemPrompt = $systemPrompt;
        $this->lastUserPrompt = $userPrompt;
        return [
            'status' => $this->status,
            'body' => $this->body,
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function extractResponseContent(string $body): string
    {
        return $body;
    }

    /**
     * {@inheritDoc}
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return $statusCode >= 500;
    }

    /**
     * {@inheritDoc}
     */
    protected function getDefaultModel(): string
    {
        return 'test-model';
    }
}
