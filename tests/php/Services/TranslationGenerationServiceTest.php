<?php

namespace SilverstripeLtd\AiTranslate\Tests\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverstripeLtd\AiTranslate\Exceptions\AIProviderException;
use SilverstripeLtd\AiTranslate\Providers\GeminiProvider;
use SilverstripeLtd\AiTranslate\Services\ContentExtractService;
use SilverstripeLtd\AiTranslate\Services\TranslationGenerationService;
use SilverstripeLtd\AiTranslate\Tests\Providers\CapturingAIProvider;
use SilverstripeLtd\AiTranslate\Tests\Providers\TestAIProvider;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationExtractedContent;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Covers generation-time locale extraction and JSON response validation.
 */
class TranslationGenerationServiceTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        Locale::class,
    ];

    private Locale $defaultLocale;
    private Locale $targetLocale;

    /**
     * Seeds locales and provider config for generation tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('AI_TRANSLATE_API_KEY', 'test-key');
        $defaultLocale = Locale::create([
            'Title' => 'English',
            'Locale' => 'en_NZ',
            'IsGlobalDefault' => 1,
        ]);
        $defaultLocale->write();
        $targetLocale = Locale::create([
            'Title' => 'Te Reo Maori',
            'Locale' => 'mi_NZ',
            'IsGlobalDefault' => 0,
        ]);
        $targetLocale->write();
        Locale::clearCached();
        $this->defaultLocale = Locale::get()->filter('Locale', 'en_NZ')->first();
        $this->targetLocale = Locale::get()->filter('Locale', 'mi_NZ')->first();
        FluentState::singleton()->setLocale($this->defaultLocale->Locale);
    }

    /**
     * Restores locale and provider state after generation tests.
     */
    protected function tearDown(): void
    {
        Environment::setEnv('AI_TRANSLATE_API_KEY', null);
        Injector::inst()->registerService(new GeminiProvider(), GeminiProvider::class);
        Locale::clearCached();
        FluentState::singleton()->setLocale(null);
        parent::tearDown();
    }

    /**
     * Confirms generation uses source-locale content and resolves target-locale metadata.
     */
    public function testGenerateForRecordReturnsStructuredSuggestions(): void
    {
        $provider = new CapturingAIProvider(json_encode([
            'translationRequired' => true,
            'suggestions' => [
                [
                    'targetKey' => 'page:title',
                    'targetType' => 'page_title',
                    'suggestedContent' => 'Wharangi hou',
                ],
                [
                    'targetKey' => 'page:content',
                    'targetType' => 'page_content',
                    'suggestedContent' => '<p>Ihirangi hou</p>',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES));
        Injector::inst()->registerService($provider, GeminiProvider::class);
        $page = $this->createLocalisedPage(
            'Default title',
            '<p>Default content</p>',
            'Current target title',
            '<p>Current target content</p>'
        );
        $service = new TranslationGenerationService();
        $result = $service->generateForRecord($page, $this->targetLocale);
        $this->assertFalse($result->alreadyMatchesLocale);
        $this->assertCount(2, $result->suggestions);
        $this->assertSame('page:title', $result->suggestions[0]->targetKey);
        $this->assertSame('Current target title', $result->suggestions[0]->currentTargetContent);
        $this->assertSame('Default title', $result->suggestions[0]->sourceLocaleContent);
        $this->assertSame('page:content', $result->suggestions[1]->targetKey);
        $this->assertSame('<p>Current target content</p>', $result->suggestions[1]->currentTargetContent);
        $this->assertSame('<p>Default content</p>', $result->suggestions[1]->sourceLocaleContent);
        $this->assertSame('html', $result->suggestions[1]->contentFormat);
        $this->assertStringContainsString('Default content', (string) $provider->lastUserPrompt);
        $this->assertStringContainsString('Current target content', (string) $provider->lastUserPrompt);
    }

    /**
     * Confirms already-translated pages return a locked no-op result.
     */
    public function testGenerateForRecordReturnsAlreadyMatchesLocaleResult(): void
    {
        Injector::inst()->registerService(new TestAIProvider([
            ['status' => 200, 'body' => json_encode([
                'translationRequired' => false,
                'suggestions' => [],
            ], JSON_UNESCAPED_SLASHES)],
        ]), GeminiProvider::class);
        $page = $this->createLocalisedPage(
            'Default title',
            '<p>Default content</p>',
            'Bereits ubersetzt',
            '<p>Bereits ubersetzt</p>'
        );
        $service = new TranslationGenerationService();
        $result = $service->generateForRecord($page, $this->targetLocale);
        $this->assertTrue($result->alreadyMatchesLocale);
        $this->assertSame([], $result->suggestions);
    }

    /**
     * Supplies malformed provider responses that should be rejected.
     *
     * @return array<string, array{body: string, message: string}>
     */
    public static function provideGenerateForRecordRejectsMalformedProviderResponses(): array
    {
        return [
            'invalid-json' => [
                'body' => '{broken',
                'message' => 'not valid JSON',
            ],
            'missing-suggestions-array' => [
                'body' => json_encode(['translationRequired' => true, 'wrong' => []], JSON_UNESCAPED_SLASHES),
                'message' => 'missing suggestions array',
            ],
            'missing-translation-required' => [
                'body' => json_encode(['suggestions' => []], JSON_UNESCAPED_SLASHES),
                'message' => 'missing translationRequired flag',
            ],
            'unexpected-target' => [
                'body' => json_encode([
                    'translationRequired' => true,
                    'suggestions' => [[
                        'targetKey' => 'page:summary',
                        'targetType' => 'page_content',
                        'suggestedContent' => 'Unexpected',
                    ]],
                ], JSON_UNESCAPED_SLASHES),
                'message' => 'unexpected target',
            ],
            'wrong-target-type' => [
                'body' => json_encode([
                    'translationRequired' => true,
                    'suggestions' => [[
                        'targetKey' => 'page:title',
                        'targetType' => 'page_content',
                        'suggestedContent' => 'Wrong type',
                    ], [
                        'targetKey' => 'page:content',
                        'targetType' => 'page_content',
                        'suggestedContent' => '<p>Okay</p>',
                    ]],
                ], JSON_UNESCAPED_SLASHES),
                'message' => 'wrong targetType',
            ],
            'duplicate-target' => [
                'body' => json_encode([
                    'translationRequired' => true,
                    'suggestions' => [[
                        'targetKey' => 'page:title',
                        'targetType' => 'page_title',
                        'suggestedContent' => 'First',
                    ], [
                        'targetKey' => 'page:title',
                        'targetType' => 'page_title',
                        'suggestedContent' => 'Second',
                    ], [
                        'targetKey' => 'page:content',
                        'targetType' => 'page_content',
                        'suggestedContent' => '<p>Okay</p>',
                    ]],
                ], JSON_UNESCAPED_SLASHES),
                'message' => 'duplicate suggestions',
            ],
            'missing-target' => [
                'body' => json_encode([
                    'translationRequired' => true,
                    'suggestions' => [[
                        'targetKey' => 'page:title',
                        'targetType' => 'page_title',
                        'suggestedContent' => 'Only one suggestion',
                    ]],
                ], JSON_UNESCAPED_SLASHES),
                'message' => 'missing suggestion',
            ],
            'suggestions-present-when-translation-not-required' => [
                'body' => json_encode([
                    'translationRequired' => false,
                    'suggestions' => [[
                        'targetKey' => 'page:title',
                        'targetType' => 'page_title',
                        'suggestedContent' => 'Should not be present',
                    ]],
                ], JSON_UNESCAPED_SLASHES),
                'message' => 'must not include suggestions',
            ],
        ];
    }

    /**
     * Confirms malformed provider responses are rejected.
     */
    #[DataProvider('provideGenerateForRecordRejectsMalformedProviderResponses')]
    public function testGenerateForRecordRejectsMalformedProviderResponses(
        string $body,
        string $message
    ): void {
        Injector::inst()->registerService(new TestAIProvider([
            ['status' => 200, 'body' => $body],
        ]), GeminiProvider::class);
        $page = $this->createLocalisedPage(
            'Default title',
            '<p>Default content</p>',
            'Current target title',
            '<p>Current target content</p>'
        );
        $service = new TranslationGenerationService();
        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage($message);
        $service->generateForRecord($page, $this->targetLocale);
    }

    /**
     * Confirms empty source content skips the provider call.
     */
    public function testGenerateForRecordReturnsNullForEmptySourceContent(): void
    {
        $provider = new TestAIProvider([
            ['status' => 200, 'body' => '{"translationRequired":true,"suggestions":[]}'],
        ]);
        Injector::inst()->registerService($provider, GeminiProvider::class);
        $page = $this->createLocalisedPage('Default title', '<p>Default content</p>', '', '');
        $contentExtractService = new class extends ContentExtractService {
            /**
             * Returns an empty extraction result.
             */
            public function extract(
                DataObject $record,
                Locale $sourceLocale,
                Locale $targetLocale
            ): TranslationExtractedContent {
                return new TranslationExtractedContent('', [], []);
            }
        };
        $service = new TranslationGenerationService($contentExtractService);
        $this->assertNull($service->generateForRecord($page, $this->targetLocale));
        $this->assertSame(0, $provider->callCount);
    }

    /**
     * Creates a page with separate source and target locale draft content.
     */
    private function createLocalisedPage(
        string $sourceTitle,
        string $sourceContent,
        string $targetTitle,
        string $targetContent
    ): SiteTree {
        $page = Versioned::withVersionedMode(function () use ($sourceTitle, $sourceContent): SiteTree {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('en_NZ');
            $page = SiteTree::create([
                'Title' => $sourceTitle,
                'Content' => $sourceContent,
            ]);
            $page->write();
            return DataObject::get(SiteTree::class)->byID($page->ID);
        });
        Versioned::withVersionedMode(function () use ($page, $targetTitle, $targetContent): void {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('mi_NZ');
            /** @var SiteTree $targetPage */
            $targetPage = DataObject::get(SiteTree::class)->byID($page->ID);
            $targetPage->Title = $targetTitle;
            $targetPage->Content = $targetContent;
            $targetPage->write();
        });
        FluentState::singleton()->setLocale('en_NZ');
        return $page;
    }
}
