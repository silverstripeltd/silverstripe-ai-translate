<?php

namespace SilverstripeLtd\AiTranslate\Tests\Providers;

use SilverstripeLtd\AiTranslate\Exceptions\AIProviderException;
use SilverstripeLtd\AiTranslate\Providers\AnthropicProvider;
use SilverstripeLtd\AiTranslate\Providers\GeminiProvider;
use SilverstripeLtd\AiTranslate\Providers\OpenAIProvider;
use SilverstripeLtd\AiTranslate\Providers\ProviderFactory;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Ensures provider resolution respects environment configuration.
 */
class ProviderFactoryTest extends SapphireTest
{
    private StubProvider $geminiProvider;
    private StubProvider $openAiProvider;
    private StubProvider $anthropicProvider;

    /**
     * Register stub providers for provider factory tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->geminiProvider = new StubProvider();
        $this->openAiProvider = new StubProvider();
        $this->anthropicProvider = new StubProvider();

        Injector::inst()->registerService($this->geminiProvider, GeminiProvider::class);
        Injector::inst()->registerService($this->openAiProvider, OpenAIProvider::class);
        Injector::inst()->registerService($this->anthropicProvider, AnthropicProvider::class);
    }

    /**
     * Reset environment after provider tests.
     */
    protected function tearDown(): void
    {
        Environment::setEnv('AI_TRANSLATE_PROVIDER', null);
        parent::tearDown();
    }

    /**
     * Ensure the factory defaults to Gemini when env is empty.
     */
    public function testDefaultsToGeminiWhenEnvEmpty(): void
    {
        Environment::setEnv('AI_TRANSLATE_PROVIDER', '');
        $factory = new ProviderFactory();
        $this->assertSame($this->geminiProvider, $factory->getProvider());
    }

    /**
     * Ensure the factory returns OpenAI when configured.
     */
    public function testSelectsOpenAiProvider(): void
    {
        Environment::setEnv('AI_TRANSLATE_PROVIDER', 'openai');
        $factory = new ProviderFactory();
        $this->assertSame($this->openAiProvider, $factory->getProvider());
    }

    /**
     * Ensure unknown providers throw a provider exception.
     */
    public function testThrowsForUnknownProvider(): void
    {
        Environment::setEnv('AI_TRANSLATE_PROVIDER', 'unknown');
        $factory = new ProviderFactory();
        $this->expectException(AIProviderException::class);
        $factory->getProvider();
    }
}
