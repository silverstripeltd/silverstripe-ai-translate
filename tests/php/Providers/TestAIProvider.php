<?php

namespace SilverstripeLtd\AiTranslate\Tests\Providers;

use SilverstripeLtd\AiTranslate\Providers\AbstractAIProvider;

/**
 * Test provider with a scripted response queue.
 */
class TestAIProvider extends AbstractAIProvider
{
    /**
     * @var array<int, array{status: int, body: string}>
     */
    private array $responses;

    public int $callCount = 0;

    /**
     * @param array<int, array{status: int, body: string}> $responses
     */
    public function __construct(array $responses)
    {
        parent::__construct();
        $this->responses = $responses;
    }

    /**
     * {@inheritDoc}
     */
    protected function performRequest(string $systemPrompt, string $userPrompt): array
    {
        $this->callCount++;
        $response = array_shift($this->responses);
        if ($response) {
            return $response;
        }
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
        return $body;
    }

    /**
     * {@inheritDoc}
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * {@inheritDoc}
     */
    protected function getDefaultModel(): string
    {
        return 'test-model';
    }

    /**
     * Expose the resolved timeout for tests.
     */
    public function getResolvedTimeout(): int
    {
        return $this->getTimeout();
    }

    /**
     * Expose the resolved temperature for tests.
     */
    public function getResolvedTemperature(): float
    {
        return $this->getTemperature();
    }

    /**
     * Expose the resolved thinking level for tests.
     */
    public function getResolvedThinkingLevel(): string
    {
        return $this->getThinkingLevel();
    }
}
