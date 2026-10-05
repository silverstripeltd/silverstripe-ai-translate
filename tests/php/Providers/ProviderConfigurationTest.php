<?php

namespace SilverstripeLtd\AiTranslate\Tests\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverstripeLtd\AiCore\Completion\SimpleCompletion;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Provider\ProviderFactory;
use SilverstripeLtd\AiCore\Settings\EnvProviderSettings;
use SilverstripeLtd\AiCore\Testing\ScriptedProvider;
use SilverstripeLtd\AiCore\Testing\StubProviderFactory;
use SilverstripeLtd\AiTranslate\Services\TranslationGenerationService;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Covers the AI Translate provider defaults and how completions reach the configured provider.
 */
class ProviderConfigurationTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const ENV_NAMES = [
        'PROVIDER',
        'API_KEY',
        'MODEL',
        'MAX_TOKENS',
        'REQUEST_TIMEOUT',
        'TEMPERATURE',
        'THINKING_LEVEL',
    ];

    private array $originalEnv = [];

    /**
     * Clears every module and shared provider variable before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENV_NAMES as $name) {
            foreach (['AI_TRANSLATE_' . $name, 'AI_' . $name] as $variable) {
                $this->originalEnv[$variable] = Environment::getEnv($variable);
                Environment::setEnv($variable, null);
            }
        }
    }

    /**
     * Restores the provider variables and the real provider factory.
     */
    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $variable => $value) {
            Environment::setEnv($variable, $value);
        }
        Injector::inst()->unregisterNamedObject(ProviderFactory::class);
        parent::tearDown();
    }

    /**
     * Confirms the module defaults match the values the module has always used.
     */
    public function testDefaultsComeFromModuleConfig(): void
    {
        $settings = $this->settings();
        $this->assertSame('gemini', $settings->getProviderName());
        $this->assertSame('gemini-3.1-flash-lite', $settings->getModel());
        $this->assertSame(2000, $settings->getMaxTokens());
        $this->assertSame(15, $settings->getTimeoutSeconds());
        $this->assertSame(1.0, $settings->getTemperature());
        $this->assertSame('low', $settings->getThinkingLevel());
    }

    /**
     * Supplies each provider with its default model and thinking level.
     *
     * @return array<string, array{string, string, string|null}>
     */
    public static function provideProviderDefaults(): array
    {
        return [
            'gemini' => ['gemini', 'gemini-3.1-flash-lite', 'low'],
            'openai' => ['openai', 'gpt-5-mini', null],
            'anthropic' => ['anthropic', 'claude-haiku-4-5', null],
        ];
    }

    /**
     * Confirms each provider gets its own default model and only Gemini a thinking level.
     */
    #[DataProvider('provideProviderDefaults')]
    public function testEachProviderHasItsOwnDefaults(string $provider, string $model, ?string $thinkingLevel): void
    {
        Environment::setEnv('AI_TRANSLATE_PROVIDER', $provider);
        $settings = $this->settings();
        $this->assertSame($provider, $settings->getProviderName());
        $this->assertSame($model, $settings->getModel());
        $this->assertSame($thinkingLevel, $settings->getThinkingLevel());
    }

    /**
     * Confirms every AI_TRANSLATE_* variable overrides the module defaults.
     */
    public function testModuleEnvironmentVariablesOverrideDefaults(): void
    {
        Environment::setEnv('AI_TRANSLATE_PROVIDER', 'openai');
        Environment::setEnv('AI_TRANSLATE_API_KEY', 'module-key');
        Environment::setEnv('AI_TRANSLATE_MODEL', 'gpt-custom');
        Environment::setEnv('AI_TRANSLATE_MAX_TOKENS', '4096');
        Environment::setEnv('AI_TRANSLATE_REQUEST_TIMEOUT', '45');
        Environment::setEnv('AI_TRANSLATE_TEMPERATURE', '0');
        Environment::setEnv('AI_TRANSLATE_THINKING_LEVEL', 'none');
        $settings = $this->settings();
        $this->assertSame('openai', $settings->getProviderName());
        $this->assertSame('module-key', $settings->getApiKey());
        $this->assertSame('gpt-custom', $settings->getModel());
        $this->assertSame(4096, $settings->getMaxTokens());
        $this->assertSame(45, $settings->getTimeoutSeconds());
        $this->assertSame(0.0, $settings->getTemperature());
        $this->assertSame('none', $settings->getThinkingLevel());
    }

    /**
     * Confirms the shared AI_* variables apply when no module variable is set.
     */
    public function testSharedEnvironmentVariablesAreTheFallback(): void
    {
        Environment::setEnv('AI_PROVIDER', 'anthropic');
        Environment::setEnv('AI_API_KEY', 'shared-key');
        Environment::setEnv('AI_REQUEST_TIMEOUT', '30');
        $settings = $this->settings();
        $this->assertSame('anthropic', $settings->getProviderName());
        $this->assertSame('shared-key', $settings->getApiKey());
        $this->assertSame('claude-haiku-4-5', $settings->getModel());
        $this->assertSame(30, $settings->getTimeoutSeconds());
        Environment::setEnv('AI_TRANSLATE_API_KEY', 'module-key');
        $this->assertSame('module-key', $settings->getApiKey());
    }

    /**
     * Confirms the real provider is built with the module defaults.
     */
    public function testProviderReceivesModuleDefaults(): void
    {
        $options = $this->completion()->getProvider()->getDefaultOptions();
        $this->assertSame('gemini-3.1-flash-lite', $options->model);
        $this->assertSame(2000, $options->maxTokens);
        $this->assertSame(15, $options->timeoutSeconds);
        $this->assertSame('low', $options->reasoningEffort);
    }

    /**
     * Confirms an unknown provider name blocks generation.
     */
    public function testUnknownProviderIsBlocking(): void
    {
        Environment::setEnv('AI_TRANSLATE_PROVIDER', 'unknown');
        try {
            $this->completion()->complete('system', 'user');
            $this->fail('Expected a ProviderException');
        } catch (ProviderException $exception) {
            $this->assertTrue($exception->isBlocking());
            $this->assertStringContainsString('unknown', $exception->getMessage());
        }
    }

    /**
     * Confirms a missing API key blocks generation and names the variable to set.
     */
    public function testMissingApiKeyIsBlocking(): void
    {
        try {
            $this->completion()->complete('system', 'user');
            $this->fail('Expected a ProviderException');
        } catch (ProviderException $exception) {
            $this->assertTrue($exception->isBlocking());
            $this->assertFalse($exception->isTransient());
            $this->assertStringContainsString('AI_TRANSLATE_API_KEY', $exception->getMessage());
        }
    }

    /**
     * Confirms the provider text is returned with surrounding whitespace removed.
     */
    public function testReturnsResponseContent(): void
    {
        $provider = new ScriptedProvider([ScriptedProvider::text("Translated output\n")]);
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);
        $this->assertSame('Translated output', $this->completion()->complete('system', 'user'));
        $this->assertSame('system', $provider->getLastRequest()->system);
        $this->assertSame('user', $provider->getLastRequest()->messages[0]->getText());
    }

    /**
     * Confirms an empty reply is a permanent failure.
     */
    public function testEmptyReplyThrows(): void
    {
        $provider = new ScriptedProvider([ScriptedProvider::text('  ')]);
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);
        try {
            $this->completion()->complete('system', 'user');
            $this->fail('Expected a ProviderException');
        } catch (ProviderException $exception) {
            $this->assertFalse($exception->isTransient());
            $this->assertFalse($exception->isBlocking());
        }
    }

    /**
     * Confirms a transient failure is reported once and never retried.
     */
    public function testTransientFailureDoesNotRetry(): void
    {
        $provider = new ScriptedProvider([
            static function (): never {
                throw ProviderException::transient('Rate limited', 429);
            },
            ScriptedProvider::text('should not be used'),
        ]);
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);
        try {
            $this->completion()->complete('system', 'user');
            $this->fail('Expected a ProviderException');
        } catch (ProviderException $exception) {
            $this->assertTrue($exception->isTransient());
        }
        $this->assertCount(1, $provider->getRequests());
        $this->assertSame(1, $provider->getRemainingCount());
    }

    /**
     * Returns the settings the generation service uses.
     */
    private function settings(): EnvProviderSettings
    {
        return EnvProviderSettings::forModule(TranslationGenerationService::SETTINGS_PREFIX);
    }

    /**
     * Returns a completion bound to the module settings.
     */
    private function completion(): SimpleCompletion
    {
        return SimpleCompletion::create($this->settings());
    }
}
