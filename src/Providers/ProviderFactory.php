<?php

namespace SilverstripeLtd\AiTranslate\Providers;

use SilverstripeLtd\AiTranslate\Exceptions\AIProviderException;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;

/**
 * Resolves the configured AI provider implementation.
 */
class ProviderFactory
{
    private LoggerInterface $logger;

    /**
     * Configure the factory with optional dependencies.
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?: Injector::inst()->get(LoggerInterface::class);
    }

    /**
     * Return the provider implementation configured via environment.
     *
     * @throws AIProviderException
     */
    public function getProvider(): AbstractAIProvider
    {
        $provider = 'gemini';
        if (Environment::hasEnv('AI_TRANSLATE_PROVIDER')) {
            $env = Environment::getEnv('AI_TRANSLATE_PROVIDER');
            if ($env !== null && $env !== '' && $env !== false) {
                $provider = (string)$env;
            }
        }
        $provider = strtolower($provider);
        $providerClass = null;

        switch ($provider) {
            case 'gemini':
                $providerClass = GeminiProvider::class;
                break;
            case 'openai':
                $providerClass = OpenAIProvider::class;
                break;
            case 'anthropic':
                $providerClass = AnthropicProvider::class;
                break;
            default:
                $this->logger->warning('Unknown AI provider configured', ['provider' => $provider]);
                throw new AIProviderException('Provider not yet implemented');
        }
        return Injector::inst()->get($providerClass);
    }
}
