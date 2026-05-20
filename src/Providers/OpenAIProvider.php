<?php

namespace SilverstripeLtd\AiTranslate\Providers;

use SilverstripeLtd\AiTranslate\Exceptions\AIProviderException;

/**
 * Provider integration for OpenAI chat completions.
 */
class OpenAIProvider extends AbstractAIProvider
{
    /**
     * Send a request to the OpenAI API.
     *
     * @return array{status: int, body: string}
     */
    protected function performRequest(string $systemPrompt, string $userPrompt): array
    {
        $apiKey = $this->getApiKey();
        $url = 'https://api.openai.com/v1/chat/completions';
        $payload = [
            'model' => $this->getModel(),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => $this->getTemperature(),
            'max_tokens' => $this->getMaxTokens(),
        ];
        return $this->performJsonRequest($url, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ], $payload, 'OpenAI');
    }

    /**
     * Extract the text from the OpenAI response payload.
     */
    protected function extractResponseContent(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new AIProviderException('OpenAI returned invalid JSON');
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        $content = is_string($content) ? trim($content) : '';
        if ($content === '') {
            throw new AIProviderException('OpenAI response missing content');
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
     * Return the default OpenAI model name.
     */
    protected function getDefaultModel(): string
    {
        return 'gpt-5-mini';
    }
}
