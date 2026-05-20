<?php

namespace SilverstripeLtd\AiTranslate\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiTranslate\Exceptions\AIProviderException;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;

/**
 * Base provider implementation with shared HTTP/error handling.
 */
abstract class AbstractAIProvider
{
    protected LoggerInterface $logger;

    /**
     * Configure the provider with optional dependencies.
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?: Injector::inst()->get(LoggerInterface::class);
    }

    /**
     * Generate a plain text response using the provider.
     *
     * @throws AIProviderException
     */
    public function generate(string $systemPrompt, string $userPrompt): string
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            $this->logger->warning('AI provider API key missing', ['provider' => static::class]);
            throw new AIProviderException('AI_TRANSLATE_API_KEY is not configured', false, true);
        }

        $model = $this->getModel();
        $this->logger->info('AI provider request starting', [
            'provider' => static::class,
            'model' => $model,
            'timeout' => $this->getTimeout(),
        ]);
        $startedAt = microtime(true);
        $loggedFailure = false;

        try {
            $response = $this->performRequest($systemPrompt, $userPrompt);
            $status = $response['status'] ?? 0;
            $body = $response['body'] ?? '';
            $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
            $this->logger->debug('AI provider response received', [
                'provider' => static::class,
                'status' => $status,
                'durationMs' => $durationMs,
            ]);

            if ($status >= 400) {
                $message = $this->extractErrorMessage($body) ?: 'AI provider request failed';
                $this->logger->warning('AI provider request failed', [
                    'provider' => static::class,
                    'status' => $status,
                    'message' => $message,
                ]);
                $loggedFailure = true;
                throw new AIProviderException(
                    $message,
                    $this->isTransientStatus($status),
                    $this->isBlockingStatus($status)
                );
            }
            return $this->extractResponseContent($body);
        } catch (AIProviderException $exception) {
            if (!$loggedFailure) {
                $this->logger->warning('AI provider error', [
                    'provider' => static::class,
                    'message' => $exception->getMessage(),
                    'transient' => $exception->isTransient(),
                ]);
            }
            throw $exception;
        }
    }

    /**
     * Resolve the API key for this provider.
     */
    protected function getApiKey(): string
    {
        if (!Environment::hasEnv('AI_TRANSLATE_API_KEY')) {
            return '';
        }

        $env = Environment::getEnv('AI_TRANSLATE_API_KEY');
        return $env !== false ? (string)$env : '';
    }

    /**
     * Resolve the model to use for this provider.
     */
    protected function getModel(): string
    {
        $env = Environment::hasEnv('AI_TRANSLATE_MODEL')
            ? Environment::getEnv('AI_TRANSLATE_MODEL')
            : null;
        if ($env !== null && $env !== '' && $env !== false) {
            return (string)$env;
        }

        $config = Config::inst()->get(static::class, 'model');
        if ($config) {
            return (string)$config;
        }
        return $this->getDefaultModel();
    }

    /**
     * Resolve the provider-specific thinking level.
     */
    protected function getThinkingLevel(): string
    {
        $env = Environment::hasEnv('AI_TRANSLATE_THINKING_LEVEL')
            ? Environment::getEnv('AI_TRANSLATE_THINKING_LEVEL')
            : null;
        return $env !== null && $env !== '' && $env !== false ? (string)$env : 'low';
    }

    /**
     * Resolve the provider temperature.
     */
    protected function getTemperature(): float
    {
        $env = Environment::hasEnv('AI_TRANSLATE_TEMPERATURE')
            ? Environment::getEnv('AI_TRANSLATE_TEMPERATURE')
            : null;
        return $env !== null && $env !== '' && $env !== false ? (float)$env : 1.0;
    }

    /**
     * Resolve the provider max token limit.
     */
    protected function getMaxTokens(): int
    {
        $env = Environment::hasEnv('AI_TRANSLATE_MAX_TOKENS')
            ? Environment::getEnv('AI_TRANSLATE_MAX_TOKENS')
            : null;
        $value = $env !== null && $env !== '' && $env !== false ? (int)$env : 2000;
        return $value > 0 ? $value : 2000;
    }

    /**
     * Resolve the request timeout in seconds.
     */
    protected function getTimeout(): int
    {
        $env = Environment::hasEnv('AI_TRANSLATE_REQUEST_TIMEOUT')
            ? Environment::getEnv('AI_TRANSLATE_REQUEST_TIMEOUT')
            : null;
        if ($env !== null && $env !== '' && $env !== false) {
            $value = (int)$env;
            if ($value > 0) {
                return $value;
            }
        }
        return 15;
    }

    /**
     * Send a JSON request with shared transport handling.
     */
    protected function performJsonRequest(
        string $url,
        array $headers,
        array $payload,
        string $providerName
    ): array {
        $timeout = $this->getTimeout();
        $client = $this->createHttpClient($timeout);
        try {
            $response = $client->post($url, [
                'headers' => $headers,
                'json' => $payload,
                'http_errors' => false,
            ]);
        } catch (RequestException $exception) {
            $handlerContext = $exception->getHandlerContext();
            $errno = isset($handlerContext['errno']) ? (int)$handlerContext['errno'] : 0;
            $timedOut = (bool)($handlerContext['timed_out'] ?? false);
            $error = isset($handlerContext['error']) ? (string)$handlerContext['error'] : $exception->getMessage();
            $message = $errno === CURLE_OPERATION_TIMEDOUT
                || $timedOut
                ? sprintf('%s request timed out after %d seconds', $providerName, $timeout)
                : sprintf('%s request failed: %s', $providerName, $error);
            $this->logger->warning('AI provider connection failed', [
                'provider' => static::class,
                'endpoint' => $url,
                'errno' => $errno,
                'error' => $error,
                'timeout' => $timeout,
            ]);
            throw new AIProviderException($message, true);
        }
        return [
            'status' => $response->getStatusCode(),
            'body' => (string)$response->getBody(),
        ];
    }

    /**
     * Create the shared HTTP client.
     */
    protected function createHttpClient(int $timeout): Client
    {
        return new Client([
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
        ]);
    }

    /**
     * Extract a provider error message from the response body.
     */
    private function extractErrorMessage(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            return (string)$decoded['error']['message'];
        }
        return '';
    }

    /**
     * Perform the provider request.
     *
     * @return array{status: int, body: string}
     */
    abstract protected function performRequest(string $systemPrompt, string $userPrompt): array;

    /**
     * Extract the response content string from the provider response body.
     *
     * @throws AIProviderException
     */
    abstract protected function extractResponseContent(string $body): string;

    /**
     * Determine whether the status code is transient.
     */
    abstract protected function isTransientStatus(int $statusCode): bool;

    /**
     * Return the default model name for this provider.
     */
    abstract protected function getDefaultModel(): string;

    /**
     * Determine whether the status code should block further processing.
     */
    private function isBlockingStatus(int $statusCode): bool
    {
        return in_array($statusCode, [401, 403], true);
    }
}
