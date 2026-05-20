<?php

namespace SilverstripeLtd\AiTranslate\Tests\Providers;

use SilverstripeLtd\AiTranslate\Exceptions\AIProviderException;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

/**
 * Tests shared provider configuration logic.
 */
class AbstractAIProviderTest extends SapphireTest
{
    /**
     * Configure environment for provider tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('AI_TRANSLATE_API_KEY', 'test-key');
    }

    /**
     * Reset environment after tests.
     */
    protected function tearDown(): void
    {
        Environment::setEnv('AI_TRANSLATE_API_KEY', null);
        Environment::setEnv('AI_TRANSLATE_REQUEST_TIMEOUT', null);
        Environment::setEnv('AI_TRANSLATE_THINKING_LEVEL', null);
        Environment::setEnv('AI_TRANSLATE_TEMPERATURE', null);
        parent::tearDown();
    }

    /**
     * Ensure missing API keys throw provider exceptions.
     */
    public function testMissingApiKeyThrows(): void
    {
        Environment::setEnv('AI_TRANSLATE_API_KEY', null);
        $provider = new TestAIProvider([
            ['status' => 200, 'body' => 'ok'],
        ]);

        $this->expectException(AIProviderException::class);
        $provider->generate('system', 'user');
    }

    /**
     * Ensure response content is returned on success.
     */
    public function testReturnsResponseContent(): void
    {
        $provider = new TestAIProvider([
            ['status' => 200, 'body' => 'Translated output'],
        ]);

        $result = $provider->generate('system', 'user');
        $this->assertSame('Translated output', $result);
    }
}
