<?php

namespace SilverstripeLtd\AiTranslate\Providers;

use SilverstripeLtd\AiTranslate\Exceptions\AIProviderException;

/**
 * Provider integration for Anthropic Claude.
 */
class AnthropicProvider extends AbstractAIProvider
{
    /**
     * Send a request to the Anthropic API.
     *
     * @return array{status: int, body: string}
     */
    protected function performRequest(string $systemPrompt, string $userPrompt): array
    {
        $apiKey = $this->getApiKey();
        $url = 'https://api.anthropic.com/v1/messages';
        $payload = [
            'model' => $this->getModel(),
            'max_tokens' => $this->getMaxTokens(),
            'temperature' => $this->getTemperature(),
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];
        return $this->performJsonRequest($url, [
            'Content-Type' => 'application/json',
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ], $payload, 'Anthropic');
    }

    /**
     * Extract the text from the Anthropic response payload.
     */
    protected function extractResponseContent(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new AIProviderException('Anthropic returned invalid JSON');
        }

        $content = $decoded['content'][0]['text'] ?? null;
        $content = is_string($content) ? trim($content) : '';
        if ($content === '') {
            throw new AIProviderException('Anthropic response missing content');
        }
        return $content;
    }

    /**
     * Check whether the status code indicates a transient failure.
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * Return the default Anthropic model name.
     */
    protected function getDefaultModel(): string
    {
        return 'claude-haiku-4-5';
    }
}
