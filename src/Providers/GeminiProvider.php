<?php

namespace SilverstripeLtd\AiTranslate\Providers;

use SilverstripeLtd\AiTranslate\Exceptions\AIProviderException;

/**
 * Provider integration for Google Gemini.
 */
class GeminiProvider extends AbstractAIProvider
{
    /**
     * Send a request to the Gemini API.
     *
     * @return array{status: int, body: string}
     */
    protected function performRequest(string $systemPrompt, string $userPrompt): array
    {
        $model = $this->getModel();
        $apiKey = $this->getApiKey();
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode($model)
        );

        $payload = [
            'systemInstruction' => [
                'role' => 'system',
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $this->getTemperature(),
                'maxOutputTokens' => $this->getMaxTokens(),
            ],
        ];

        $thinkingLevel = $this->getThinkingLevel();
        if ($thinkingLevel !== 'none') {
            $payload['generationConfig']['thinkingConfig'] = [
                'thinkingLevel' => $thinkingLevel,
            ];
        }
        return $this->performJsonRequest($url, [
            'Content-Type' => 'application/json',
            'x-goog-api-key' => $apiKey,
        ], $payload, 'Gemini');
    }

    /**
     * Extract the text from the Gemini response payload.
     */
    protected function extractResponseContent(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new AIProviderException('Gemini returned invalid JSON');
        }

        $candidate = $decoded['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($candidate)) {
            throw new AIProviderException('Gemini response missing content');
        }

        $text = '';
        foreach ($candidate as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        $text = trim($text);
        if ($text === '') {
            throw new AIProviderException('Gemini response contained no text');
        }
        return $text;
    }

    /**
     * Check whether the status code indicates a transient failure.
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * Return the default Gemini model name.
     */
    protected function getDefaultModel(): string
    {
        return 'gemini-3.1-flash-lite';
    }
}
