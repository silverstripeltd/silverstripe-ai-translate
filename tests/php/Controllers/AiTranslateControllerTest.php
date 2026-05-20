<?php

namespace SilverstripeLtd\AiTranslate\Tests\Controllers;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\ElementContent;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiTranslate\Controllers\AiTranslateController;
use SilverstripeLtd\AiTranslate\Forms\AiTranslateForm;
use SilverstripeLtd\AiTranslate\Providers\GeminiProvider;
use SilverstripeLtd\AiTranslate\Services\AiTranslateRateLimiter;
use SilverstripeLtd\AiTranslate\Tests\Providers\TestAIProvider;
use SilverstripeLtd\AiTranslate\Tests\RestrictedTranslatePage;
use SilverstripeLtd\AiTranslate\Tests\TestLogger;
use SilverstripeLtd\AiTranslate\Tests\TranslateTestElementalPage;
use SilverstripeLtd\AiTranslate\Tests\TranslateTestLockedElement;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationRewriteTarget;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Covers schema, translate, and apply endpoint behaviour.
 */
class AiTranslateControllerTest extends FunctionalTest
{
    protected static $extra_dataobjects = [
        Locale::class,
        RestrictedTranslatePage::class,
        TranslateTestElementalPage::class,
        TranslateTestLockedElement::class,
        ElementContent::class,
    ];

    protected static $required_extensions = [
        TranslateTestElementalPage::class => [
            ElementalPageExtension::class,
        ],
    ];

    private Locale $defaultLocale;
    private Locale $targetLocale;
    private LoggerInterface $originalLogger;
    private TestLogger $logger;

    /**
     * Seeds locales, auth, and shared services for controller tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logInWithPermission('ADMIN');
        SecurityToken::enable();
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
        FluentState::singleton()->setLocale($this->targetLocale->Locale);
        $this->originalLogger = Injector::inst()->get(LoggerInterface::class);
        $this->logger = new TestLogger();
        Injector::inst()->registerService($this->logger, LoggerInterface::class);
        $this->session()->set(SecurityToken::inst()->getName(), SecurityToken::inst()->getValue());
    }

    /**
     * Restores locale, logging, and provider state after controller tests.
     */
    protected function tearDown(): void
    {
        Config::modify()->set(AiTranslateRateLimiter::class, 'max_requests', 10);
        Config::modify()->set(AiTranslateRateLimiter::class, 'window_seconds', 300);
        Environment::setEnv('AI_TRANSLATE_API_KEY', null);
        Injector::inst()->registerService($this->originalLogger, LoggerInterface::class);
        Injector::inst()->registerService(new GeminiProvider(), GeminiProvider::class);
        Locale::clearCached();
        FluentState::singleton()->setLocale(null);
        parent::tearDown();
    }

    /**
     * Confirms boot config exposes the schema, translate, and apply URLs.
     */
    public function testClientConfigIncludesSchemaTranslateAndApplyUrls(): void
    {
        $controller = AiTranslateController::create();
        $controller->setRequest(new HTTPRequest('GET', '/admin/ai-translate'));
        $config = $controller->getClientConfig();
        $this->assertSame('admin/ai-translate/schema', $config['form']['aiTranslate']['schemaUrl']);
        $this->assertSame('admin/ai-translate/translate', $config['form']['aiTranslate']['translateUrl']);
        $this->assertSame('admin/ai-translate/apply', $config['form']['aiTranslate']['applyUrl']);
        $this->assertSame('ai-translate-modal', $config['form']['aiTranslate']['className']);
        $this->assertSame('.ai-translate-modal', $config['form']['aiTranslate']['modalSelector']);
    }

    /**
     * Confirms the schema endpoint returns schema fields plus modal metadata.
     */
    public function testSchemaEndpointReturnsSchemaAndMeta(): void
    {
        $page = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>',
            'Current title',
            '<p>Current content</p>'
        );
        $response = $this->get(
            '/admin/ai-translate/schema/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            null,
            ['X-FormSchema-Request' => 'schema,state']
        );
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('schema', $payload);
        $this->assertSame(
            'Translate to te reo maori with AI',
            $payload['meta']['aiTranslate']['title'] ?? null
        );
        $this->assertSame(
            AiTranslateForm::ALREADY_MATCHES_LOCALE_MESSAGE,
            $payload['meta']['aiTranslate']['messages']['alreadyMatchesLocale'] ?? null
        );
        $this->assertSame(
            AiTranslateForm::DRAFT_NOTICE,
            $payload['meta']['aiTranslate']['messages']['draftNotice'] ?? null
        );
        $this->assertSame(
            AiTranslateForm::EMPTY_STATE_MESSAGE,
            $payload['meta']['aiTranslate']['messages']['emptyState'] ?? null
        );
        $this->assertSame(
            AiTranslateForm::GENERATE_BUTTON_LABEL,
            $payload['meta']['aiTranslate']['labels']['generate'] ?? null
        );
        $this->assertSame(
            AiTranslateForm::REGENERATE_BUTTON_LABEL,
            $payload['meta']['aiTranslate']['labels']['regenerate'] ?? null
        );
        $this->assertSame(
            AiTranslateForm::APPLY_BUTTON_LABEL,
            $payload['meta']['aiTranslate']['labels']['apply'] ?? null
        );
        $this->assertSame(
            'admin/ai-translate/apply/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            $payload['meta']['aiTranslate']['actions']['applyUrl'] ?? null
        );
        $this->assertSame('Te Reo Maori', $payload['meta']['aiTranslate']['locale']['target']['title'] ?? null);
        $this->assertTrue($payload['meta']['aiTranslate']['state']['supportsApply'] ?? false);
        $this->assertFalse($payload['meta']['aiTranslate']['state']['storesResultsServerSide'] ?? true);
        $fieldNames = array_map(
            static fn(array $field): ?string => $field['name'] ?? null,
            $payload['schema']['fields'] ?? []
        );
        $this->assertContains('AiTranslateDraftNotice', $fieldNames);
        $this->assertContains('AiTranslateEmptyState', $fieldNames);
        $actionNames = array_map(
            static fn(array $action): ?string => $action['name'] ?? null,
            $payload['schema']['actions'] ?? []
        );
        $this->assertContains('action_AiTranslateAction', $actionNames);
    }

    /**
     * Confirms schema rejects pages that have not been localised in the active locale.
     */
    public function testSchemaEndpointRejectsUnlocalisedTargetLocalePage(): void
    {
        $page = $this->createSourceOnlyPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>'
        );
        $response = $this->get(
            '/admin/ai-translate/schema/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            null,
            ['X-FormSchema-Request' => 'schema,state']
        );
        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(
            'AI translate is only available after this page has been localised for the active locale.',
            $payload['error'] ?? null
        );
    }

    /**
     * Confirms translate rejects requests made on the default locale.
     */
    public function testTranslateEndpointRejectsDefaultLocale(): void
    {
        $page = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>',
            '',
            ''
        );
        FluentState::singleton()->setLocale($this->defaultLocale->Locale);
        $response = $this->post(
            '/admin/ai-translate/translate/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );
        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('Translations are only available for non-default locales', $payload['error'] ?? null);
    }

    /**
     * Confirms translate rejects pages that have not been localised in the active locale.
     */
    public function testTranslateEndpointRejectsUnlocalisedTargetLocalePage(): void
    {
        $page = $this->createSourceOnlyPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>'
        );
        $response = $this->post(
            '/admin/ai-translate/translate/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );
        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(
            'AI translate is only available after this page has been localised for the active locale.',
            $payload['error'] ?? null
        );
    }

    /**
     * Confirms translate returns structured suggestions and sanitised diff previews.
     */
    public function testTranslateEndpointReturnsStructuredSuggestionsAndSafeDiffHtml(): void
    {
        Injector::inst()->registerService(new TestAIProvider([
            ['status' => 200, 'body' => json_encode([
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
                        'suggestedContent' => '<p onclick="alert(1)">Suggested <strong>copy</strong></p>'
                            . '<script>alert(1)</script>',
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES)],
        ]), GeminiProvider::class);
        $page = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>',
            'Current title',
            '<p class="current">Current content</p><script>alert(1)</script>'
        );
        $response = $this->post(
            '/admin/ai-translate/translate/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertCount(2, $payload['suggestions'] ?? []);
        $this->assertSame('Default title', $payload['suggestions'][0]['sourceLocaleContent'] ?? null);
        $this->assertSame('Current title', $payload['suggestions'][0]['currentTargetContent'] ?? null);
        $this->assertSame('<p>Default content</p>', $payload['suggestions'][1]['sourceLocaleContent'] ?? null);
        $this->assertSame(
            '<p class="current">Current content</p><script>alert(1)</script>',
            $payload['suggestions'][1]['currentTargetContent'] ?? null
        );
        $this->assertSame('html', $payload['suggestions'][1]['contentFormat'] ?? null);
        $diffHtml = (string) ($payload['suggestions'][1]['diffHtml'] ?? '');
        $this->assertStringContainsString('<ins>', $diffHtml);
        $this->assertStringNotContainsString('script', $diffHtml);
        $this->assertStringNotContainsString('onclick', $diffHtml);
        $this->assertStringNotContainsString('class=', $diffHtml);
        $this->assertStringNotContainsString('<strong', $diffHtml);
    }

    /**
     * Confirms translate returns a rate-limit JSON error without making another provider call.
     */
    public function testTranslateEndpointReturnsRateLimitResponse(): void
    {
        Config::modify()->set(AiTranslateRateLimiter::class, 'max_requests', 1);
        $provider = new TestAIProvider([
            ['status' => 200, 'body' => json_encode([
                'translationRequired' => false,
                'suggestions' => [],
            ], JSON_UNESCAPED_SLASHES)],
        ]);
        Injector::inst()->registerService($provider, GeminiProvider::class);

        $page = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>',
            'Current title',
            '<p>Current content</p>'
        );

        $firstResponse = $this->post(
            '/admin/ai-translate/translate/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );
        $secondResponse = $this->post(
            '/admin/ai-translate/translate/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );

        $this->assertSame(200, $firstResponse->getStatusCode());
        $this->assertSame(429, $secondResponse->getStatusCode());
        $this->assertNotEmpty($secondResponse->getHeader('Retry-After'));

        $payload = json_decode((string) $secondResponse->getBody(), true);
        $this->assertStringContainsString(
            'Too many AI translation requests for this page.',
            $payload['error'] ?? ''
        );
        $this->assertSame(1, $provider->callCount);
    }

    /**
     * Confirms translate rate limits are tracked separately for each page.
     */
    public function testTranslateEndpointRateLimitIsScopedPerPage(): void
    {
        Config::modify()->set(AiTranslateRateLimiter::class, 'max_requests', 1);
        $provider = new TestAIProvider([
            ['status' => 200, 'body' => json_encode([
                'translationRequired' => false,
                'suggestions' => [],
            ], JSON_UNESCAPED_SLASHES)],
            ['status' => 200, 'body' => json_encode([
                'translationRequired' => false,
                'suggestions' => [],
            ], JSON_UNESCAPED_SLASHES)],
        ]);
        Injector::inst()->registerService($provider, GeminiProvider::class);

        $firstPage = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>',
            'Current title',
            '<p>Current content</p>'
        );
        $secondPage = $this->createLocalisedPage(
            SiteTree::class,
            'Second default title',
            '<p>Second default content</p>',
            'Second current title',
            '<p>Second current content</p>'
        );

        $firstResponse = $this->post(
            '/admin/ai-translate/translate/' . $firstPage->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );
        $secondResponse = $this->post(
            '/admin/ai-translate/translate/' . $secondPage->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );
        $repeatFirstResponse = $this->post(
            '/admin/ai-translate/translate/' . $firstPage->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );

        $this->assertSame(200, $firstResponse->getStatusCode());
        $this->assertSame(200, $secondResponse->getStatusCode());
        $this->assertSame(429, $repeatFirstResponse->getStatusCode());
        $this->assertSame(2, $provider->callCount);
    }

    /**
     * Confirms config overrides change both the threshold and cooldown window.
     */
    public function testTranslateEndpointRateLimitHonoursConfigOverrides(): void
    {
        Config::modify()->set(AiTranslateRateLimiter::class, 'max_requests', 1);
        Config::modify()->set(AiTranslateRateLimiter::class, 'window_seconds', 2);
        $provider = new TestAIProvider([
            ['status' => 200, 'body' => json_encode([
                'translationRequired' => false,
                'suggestions' => [],
            ], JSON_UNESCAPED_SLASHES)],
            ['status' => 200, 'body' => json_encode([
                'translationRequired' => false,
                'suggestions' => [],
            ], JSON_UNESCAPED_SLASHES)],
        ]);
        Injector::inst()->registerService($provider, GeminiProvider::class);

        $page = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>',
            'Current title',
            '<p>Current content</p>'
        );

        $firstResponse = $this->post(
            '/admin/ai-translate/translate/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );
        $secondResponse = $this->post(
            '/admin/ai-translate/translate/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );
        sleep(3);
        $thirdResponse = $this->post(
            '/admin/ai-translate/translate/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );

        $this->assertSame(200, $firstResponse->getStatusCode());
        $this->assertSame(429, $secondResponse->getStatusCode());
        $this->assertNotEmpty($secondResponse->getHeader('Retry-After'));
        $this->assertSame(200, $thirdResponse->getStatusCode());
        $this->assertSame(2, $provider->callCount);
    }

    /**
     * Confirms translate returns a locked already-matches-locale result with no suggestions.
     */
    public function testTranslateEndpointReturnsAlreadyMatchesLocaleResult(): void
    {
        Injector::inst()->registerService(new TestAIProvider([
            ['status' => 200, 'body' => json_encode([
                'translationRequired' => false,
                'suggestions' => [],
            ], JSON_UNESCAPED_SLASHES)],
        ]), GeminiProvider::class);
        $page = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>',
            'Bereits ubersetzt',
            '<p>Bereits ubersetzt</p>'
        );
        $response = $this->post(
            '/admin/ai-translate/translate/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            ['X-SecurityID' => SecurityToken::inst()->getValue()]
        );
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['alreadyMatchesLocale'] ?? false);
        $this->assertSame([], $payload['suggestions'] ?? null);
    }

    /**
     * Confirms apply sanitises page suggestions and counts skipped duplicates.
     */
    public function testApplyEndpointSanitisesPageSuggestionsAndCountsSkips(): void
    {
        $page = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>',
            'Current title',
            '<p>Current content</p>'
        );
        $response = $this->applySuggestionsJson($page, [
            'suggestions' => [
                [
                    'apply' => true,
                    'targetKey' => 'page:title',
                    'targetType' => 'page_title',
                    'targetId' => $page->ID,
                    'fieldName' => 'Title',
                    'suggestedContent' => '<strong>Updated</strong> title',
                ],
                [
                    'apply' => true,
                    'targetKey' => 'page:content',
                    'targetType' => 'page_content',
                    'targetId' => $page->ID,
                    'fieldName' => 'Content',
                    'suggestedContent' => '<p>Updated <strong>content</strong></p><script>alert(1)</script>',
                ],
                [
                    'apply' => true,
                    'targetKey' => 'page:title',
                    'targetType' => 'page_title',
                    'targetId' => $page->ID,
                    'fieldName' => 'Title',
                    'suggestedContent' => 'Duplicate title',
                ],
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(2, $payload['appliedCount'] ?? null);
        $this->assertSame(1, $payload['skippedCount'] ?? null);
        $this->assertTrue($payload['reloadRequired'] ?? false);
        /** @var SiteTree $draftPage */
        $draftPage = $this->getDraftRecordInLocale(SiteTree::class, $page->ID, 'mi_NZ');
        $this->assertSame('Updated title', $draftPage->Title);
        $this->assertSame('<p>Updated <strong>content</strong></p>', $draftPage->Content);
        $this->assertContains('duplicate-target', $this->getApplySkipReasons());
    }

    /**
     * Confirms apply skips non-array suggestion entries.
     */
    public function testApplyEndpointSkipsNonArraySuggestions(): void
    {
        $page = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>',
            'Current title',
            '<p>Current content</p>'
        );
        $response = $this->applySuggestionsJson($page, [
            'suggestions' => [
                'not-an-array',
                [
                    'apply' => true,
                    'targetKey' => 'page:title',
                    'targetType' => TranslationRewriteTarget::TYPE_PAGE_TITLE,
                    'targetId' => $page->ID,
                    'fieldName' => 'Title',
                    'suggestedContent' => 'Applied title',
                ],
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, $payload['appliedCount'] ?? null);
        $this->assertSame(1, $payload['skippedCount'] ?? null);
        /** @var SiteTree $draftPage */
        $draftPage = $this->getDraftRecordInLocale(SiteTree::class, $page->ID, 'mi_NZ');
        $this->assertSame('Applied title', $draftPage->Title);
        $this->assertContains('invalid-payload', $this->getApplySkipReasons());
    }

    /**
     * Confirms apply skips selected suggestions without a target key.
     */
    public function testApplyEndpointSkipsSuggestionsWithoutTargetKey(): void
    {
        $page = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>',
            'Current title',
            '<p>Current content</p>'
        );
        $response = $this->applySuggestionsJson($page, [
            'suggestions' => [
                [
                    'apply' => true,
                    'targetType' => TranslationRewriteTarget::TYPE_PAGE_TITLE,
                    'targetId' => $page->ID,
                    'fieldName' => 'Title',
                    'suggestedContent' => 'Ignored title',
                ],
                [
                    'apply' => true,
                    'targetKey' => 'page:title',
                    'targetType' => TranslationRewriteTarget::TYPE_PAGE_TITLE,
                    'targetId' => $page->ID,
                    'fieldName' => 'Title',
                    'suggestedContent' => 'Applied title',
                ],
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, $payload['appliedCount'] ?? null);
        $this->assertSame(1, $payload['skippedCount'] ?? null);
        /** @var SiteTree $draftPage */
        $draftPage = $this->getDraftRecordInLocale(SiteTree::class, $page->ID, 'mi_NZ');
        $this->assertSame('Applied title', $draftPage->Title);
        $this->assertContains('missing-target-key', $this->getApplySkipReasons());
    }

    /**
     * Confirms apply skips selected suggestions without string content.
     */
    public function testApplyEndpointSkipsSuggestionsWithoutStringContent(): void
    {
        $page = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>',
            'Current title',
            '<p>Current content</p>'
        );
        $response = $this->applySuggestionsJson($page, [
            'suggestions' => [
                [
                    'apply' => true,
                    'targetKey' => 'page:title',
                    'targetType' => TranslationRewriteTarget::TYPE_PAGE_TITLE,
                    'targetId' => $page->ID,
                    'fieldName' => 'Title',
                    'suggestedContent' => ['invalid'],
                ],
                [
                    'apply' => true,
                    'targetKey' => 'page:title',
                    'targetType' => TranslationRewriteTarget::TYPE_PAGE_TITLE,
                    'targetId' => $page->ID,
                    'fieldName' => 'Title',
                    'suggestedContent' => 'Applied title',
                ],
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, $payload['appliedCount'] ?? null);
        $this->assertSame(1, $payload['skippedCount'] ?? null);
        /** @var SiteTree $draftPage */
        $draftPage = $this->getDraftRecordInLocale(SiteTree::class, $page->ID, 'mi_NZ');
        $this->assertSame('Applied title', $draftPage->Title);
        $this->assertContains('missing-suggested-content', $this->getApplySkipReasons());
    }

    /**
     * Confirms apply ignores unselected suggestions without counting them as skips.
     */
    public function testApplyEndpointIgnoresUnselectedSuggestions(): void
    {
        $page = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>',
            'Current title',
            '<p>Current content</p>'
        );
        $response = $this->applySuggestionsJson($page, [
            'suggestions' => [
                [
                    'apply' => false,
                    'targetKey' => 'page:title',
                    'targetType' => TranslationRewriteTarget::TYPE_PAGE_TITLE,
                    'targetId' => $page->ID,
                    'fieldName' => 'Title',
                    'suggestedContent' => 'Ignored title',
                ],
                [
                    'targetKey' => 'page:content',
                    'targetType' => TranslationRewriteTarget::TYPE_PAGE_CONTENT,
                    'targetId' => $page->ID,
                    'fieldName' => 'Content',
                    'suggestedContent' => '<p>Ignored content</p>',
                ],
                [
                    'apply' => true,
                    'targetKey' => 'page:title',
                    'targetType' => TranslationRewriteTarget::TYPE_PAGE_TITLE,
                    'targetId' => $page->ID,
                    'fieldName' => 'Title',
                    'suggestedContent' => 'Applied title',
                ],
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, $payload['appliedCount'] ?? null);
        $this->assertSame(0, $payload['skippedCount'] ?? null);
        /** @var SiteTree $draftPage */
        $draftPage = $this->getDraftRecordInLocale(SiteTree::class, $page->ID, 'mi_NZ');
        $this->assertSame('Applied title', $draftPage->Title);
        $this->assertSame('<p>Current content</p>', $draftPage->Content);
        $this->assertSame([], $this->getApplySkipReasons());
    }

    /**
     * Supplies page suggestions with tampered target metadata.
     *
     * @return array<string, array{targetType: string, fieldName: string, targetIdOffset: int}>
     */
    public static function provideApplyEndpointSkipsTamperedTargetMetadata(): array
    {
        return [
            'target type mismatch' => [
                'targetType' => TranslationRewriteTarget::TYPE_PAGE_CONTENT,
                'fieldName' => 'Title',
                'targetIdOffset' => 0,
            ],
            'field name mismatch' => [
                'targetType' => TranslationRewriteTarget::TYPE_PAGE_TITLE,
                'fieldName' => 'Content',
                'targetIdOffset' => 0,
            ],
            'target id mismatch' => [
                'targetType' => TranslationRewriteTarget::TYPE_PAGE_TITLE,
                'fieldName' => 'Title',
                'targetIdOffset' => 999,
            ],
        ];
    }

    /**
     * Confirms apply skips selected suggestions whose metadata no longer matches the server target.
     */
    #[DataProvider('provideApplyEndpointSkipsTamperedTargetMetadata')]
    public function testApplyEndpointSkipsTamperedTargetMetadata(
        string $targetType,
        string $fieldName,
        int $targetIdOffset
    ): void {
        $page = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>',
            'Current title',
            '<p>Current content</p>'
        );
        $response = $this->applySuggestionsJson($page, [
            'suggestions' => [
                [
                    'apply' => true,
                    'targetKey' => 'page:title',
                    'targetType' => $targetType,
                    'targetId' => $page->ID + $targetIdOffset,
                    'fieldName' => $fieldName,
                    'suggestedContent' => 'Ignored title',
                ],
                [
                    'apply' => true,
                    'targetKey' => 'page:content',
                    'targetType' => TranslationRewriteTarget::TYPE_PAGE_CONTENT,
                    'targetId' => $page->ID,
                    'fieldName' => 'Content',
                    'suggestedContent' => '<p>Applied content</p>',
                ],
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, $payload['appliedCount'] ?? null);
        $this->assertSame(1, $payload['skippedCount'] ?? null);
        /** @var SiteTree $draftPage */
        $draftPage = $this->getDraftRecordInLocale(SiteTree::class, $page->ID, 'mi_NZ');
        $this->assertSame('Current title', $draftPage->Title);
        $this->assertSame('<p>Applied content</p>', $draftPage->Content);
        $this->assertContains('target-metadata-mismatch', $this->getApplySkipReasons());
    }

    /**
     * Confirms apply rejects pages that have not been localised in the active locale.
     */
    public function testApplyEndpointRejectsUnlocalisedTargetLocalePage(): void
    {
        $page = $this->createSourceOnlyPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>'
        );
        $this->assertFalse($this->isDraftedRecordInLocale(SiteTree::class, $page->ID, 'mi_NZ'));
        $response = $this->applySuggestionsJson($page, [
            'suggestions' => [
                [
                    'apply' => true,
                    'targetKey' => 'page:title',
                    'targetType' => 'page_title',
                    'targetId' => $page->ID,
                    'fieldName' => 'Title',
                    'suggestedContent' => 'German title',
                ],
                [
                    'apply' => true,
                    'targetKey' => 'page:content',
                    'targetType' => 'page_content',
                    'targetId' => $page->ID,
                    'fieldName' => 'Content',
                    'suggestedContent' => '<p>German content</p>',
                ],
            ],
        ]);
        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(
            'AI translate is only available after this page has been localised for the active locale.',
            $payload['error'] ?? null
        );
        $this->assertFalse($this->isDraftedRecordInLocale(SiteTree::class, $page->ID, 'mi_NZ'));
        /** @var SiteTree $defaultDraft */
        $defaultDraft = $this->getDraftRecordInLocale(SiteTree::class, $page->ID, 'en_NZ');
        $this->assertSame('Default title', $defaultDraft->Title);
        $this->assertSame('<p>Default content</p>', $defaultDraft->Content);
    }

    /**
     * Confirms Elemental inline edit schema omits Fluent's unsupported locale grid field.
     */
    public function testElementalInlineEditSchemaOmitsFluentLocaleGridField(): void
    {
        $page = $this->createElementalPage([
            '<p>Current block</p>',
        ]);
        $element = $page->ElementalArea()->Elements()->sort('ID')->first();
        $this->assertInstanceOf(ElementContent::class, $element);
        $response = $this->get(
            '/admin/elemental-area/schema/elementForm/' . $element->ID,
            null,
            ['X-FormSchema-Request' => 'schema,state']
        );
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $fieldNames = $this->getSchemaFieldNames($payload['schema']['fields'] ?? []);
        $this->assertContains(sprintf('PageElements_%d_HTML', $element->ID), $fieldNames);
        $this->assertNotContains(sprintf('PageElements_%d_RecordLocales', $element->ID), $fieldNames);
    }

    /**
     * Confirms apply validates Elemental ownership and sanitises HTML block suggestions.
     */
    public function testApplyEndpointSanitisesElementHtmlAndSkipsDeletedOrForeignTargets(): void
    {
        $page = $this->createElementalPage([
            '<p>Current block</p>',
            '<p>Deleted block</p>',
        ]);
        $currentElement = $page->ElementalArea()->Elements()->sort('ID')->first();
        $deletedElement = $page->ElementalArea()->Elements()->sort('ID')->last();
        $deletedTargetKey = sprintf('element:%d:html', $deletedElement->ID);
        $deletedElement->delete();
        $foreignPage = $this->createElementalPage([
            '<p>Foreign block</p>',
        ]);
        $foreignElement = $foreignPage->ElementalArea()->Elements()->first();
        $foreignTargetKey = sprintf('element:%d:html', $foreignElement->ID);
        $response = $this->applySuggestionsJson($page, [
            'suggestions' => [
                [
                    'apply' => true,
                    'targetKey' => sprintf('element:%d:html', $currentElement->ID),
                    'targetType' => TranslationRewriteTarget::TYPE_ELEMENT_HTML,
                    'targetId' => $currentElement->ID,
                    'fieldName' => 'HTML',
                    'suggestedContent' => '<p onclick="alert(1)">Updated <strong>current</strong> block</p>',
                ],
                [
                    'apply' => true,
                    'targetKey' => $deletedTargetKey,
                    'targetType' => TranslationRewriteTarget::TYPE_ELEMENT_HTML,
                    'targetId' => $deletedElement->ID,
                    'fieldName' => 'HTML',
                    'suggestedContent' => '<p>Updated deleted block</p>',
                ],
                [
                    'apply' => true,
                    'targetKey' => $foreignTargetKey,
                    'targetType' => TranslationRewriteTarget::TYPE_ELEMENT_HTML,
                    'targetId' => $foreignElement->ID,
                    'fieldName' => 'HTML',
                    'suggestedContent' => '<p>Updated foreign block</p>',
                ],
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, $payload['appliedCount'] ?? null);
        $this->assertSame(2, $payload['skippedCount'] ?? null);
        $this->assertTrue($payload['reloadRequired'] ?? false);
        /** @var ElementContent $updatedCurrentElement */
        $updatedCurrentElement = $this->getDraftRecordInLocale(ElementContent::class, $currentElement->ID, 'mi_NZ');
        $this->assertSame('<p>Updated <strong>current</strong> block</p>', $updatedCurrentElement->HTML);
        /** @var ElementContent $unchangedForeignElement */
        $unchangedForeignElement = $this->getDraftRecordInLocale(ElementContent::class, $foreignElement->ID, 'mi_NZ');
        $this->assertSame('<p>Foreign block</p>', $unchangedForeignElement->HTML);
        $reasons = $this->getApplySkipReasons();
        $this->assertTrue(
            in_array('deleted-target', $reasons, true)
                || in_array('target-metadata-mismatch', $reasons, true)
        );
        $this->assertContains('foreign-target', $reasons);
    }

    /**
     * Confirms Elemental apply only mutates the active locale's block draft content.
     */
    public function testApplyEndpointKeepsElementSuggestionsIsolatedToTheTargetLocale(): void
    {
        $page = $this->createElementalPage([
            '<p>English block</p>',
            '<p>English second block</p>',
        ]);
        /** @var ElementContent|null $targetElement */
        $targetElement = $this->getElementDraftRecordInLocale($page->ID, 'mi_NZ', 0);
        $this->assertInstanceOf(ElementContent::class, $targetElement);
        $response = $this->applySuggestionsJson($page, [
            'suggestions' => [
                [
                    'apply' => true,
                    'targetKey' => sprintf('element:%d:html', $targetElement->ID),
                    'targetType' => TranslationRewriteTarget::TYPE_ELEMENT_HTML,
                    'targetId' => $targetElement->ID,
                    'fieldName' => 'HTML',
                    'suggestedContent' => '<p>Updated Maori block</p>',
                ],
            ],
        ]);
        $this->assertSame(200, $response->getStatusCode());
        /** @var ElementContent|null $updatedTargetElement */
        $updatedTargetElement = $this->getElementDraftRecordInLocale($page->ID, 'mi_NZ', 0);
        /** @var ElementContent|null $defaultElement */
        $defaultElement = $this->getDraftRecordInLocale(ElementContent::class, $targetElement->ID, 'en_NZ');
        $this->assertInstanceOf(ElementContent::class, $updatedTargetElement);
        $this->assertInstanceOf(ElementContent::class, $defaultElement);
        $this->assertSame('<p>Updated Maori block</p>', $updatedTargetElement->HTML);
        $this->assertSame('<p>English block</p>', $defaultElement->HTML);
    }

    /**
     * Confirms block canEdit() denials fail the whole apply request with the generic failure message.
     */
    public function testApplyEndpointFailsWhenTargetElementCannotBeEdited(): void
    {
        $page = $this->createElementalPageWithCustomBlocks([
            [
                'class' => TranslateTestLockedElement::class,
                'html' => '<p>Locked target block</p>',
            ],
        ]);

        /** @var TranslateTestLockedElement $lockedElement */
        $lockedElement = $this->getDraftElementInLocale(
            TranslateTestLockedElement::class,
            $page->ID,
            'mi_NZ'
        );

        $response = $this->applySuggestionsJson($page, [
            'suggestions' => [
                [
                    'apply' => true,
                    'targetKey' => 'page:title',
                    'targetType' => TranslationRewriteTarget::TYPE_PAGE_TITLE,
                    'targetId' => $page->ID,
                    'fieldName' => 'Title',
                    'suggestedContent' => 'Updated translated title',
                ],
                [
                    'apply' => true,
                    'targetKey' => sprintf('element:%d:html', $lockedElement->ID),
                    'targetType' => TranslationRewriteTarget::TYPE_ELEMENT_HTML,
                    'targetId' => $lockedElement->ID,
                    'fieldName' => 'HTML',
                    'suggestedContent' => '<p>Updated locked target block</p>',
                ],
            ],
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(AiTranslateForm::APPLY_FAILURE_MESSAGE, $payload['error'] ?? null);

        /** @var TranslateTestElementalPage $unchangedPage */
        $unchangedPage = $this->getDraftRecordInLocale(TranslateTestElementalPage::class, $page->ID, 'mi_NZ');
        /** @var TranslateTestLockedElement $unchangedElement */
        $unchangedElement = $this->getDraftRecordInLocale(
            TranslateTestLockedElement::class,
            $lockedElement->ID,
            'mi_NZ'
        );
        $this->assertSame('Elemental page', $unchangedPage->Title);
        $this->assertSame('<p>Locked target block</p>', $unchangedElement->HTML);
    }

    /**
     * Supplies post endpoints that must reject stale CSRF tokens.
     *
     * @return array<string, array{endpoint: string, payload: array}>
     */
    public static function providePostEndpointsRequireSecurityToken(): array
    {
        return [
            'translate' => [
                'endpoint' => 'translate',
                'payload' => [],
            ],
            'apply' => [
                'endpoint' => 'apply',
                'payload' => [
                    'suggestions' => [],
                ],
            ],
        ];
    }

    /**
     * Confirms translate and apply both require a valid CSRF token.
     */
    #[DataProvider('providePostEndpointsRequireSecurityToken')]
    public function testPostEndpointsRejectStaleSecurityToken(string $endpoint, array $payload): void
    {
        $page = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default content</p>',
            'Current title',
            '<p>Current content</p>'
        );
        $this->session()->set(SecurityToken::inst()->getName(), 'fresh-security-token');
        $response = $this->post(
            sprintf('/admin/ai-translate/%s/%d?fqcn=%s', $endpoint, $page->ID, rawurlencode(SiteTree::class)),
            array_merge($payload, [SecurityToken::inst()->getName() => 'stale-security-token']),
            ['X-SecurityID' => 'stale-security-token']
        );
        $this->assertSame(403, $response->getStatusCode());
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame('Session timed out, please refresh and try again.', $decoded['error'] ?? null);
    }

    /**
     * Creates a page with separate source and target locale content.
     */
    private function createLocalisedPage(
        string $className,
        string $sourceTitle,
        string $sourceContent,
        string $targetTitle,
        string $targetContent
    ): SiteTree {
        $page = Versioned::withVersionedMode(function () use ($className, $sourceTitle, $sourceContent): SiteTree {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('en_NZ');
            /** @var SiteTree $page */
            $page = $className::create([
                'Title' => $sourceTitle,
                'Content' => $sourceContent,
            ]);
            $page->write();
            return DataObject::get($className)->byID($page->ID);
        });
        Versioned::withVersionedMode(function () use ($page, $targetTitle, $targetContent): void {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('mi_NZ');
            /** @var SiteTree $targetPage */
            $targetPage = DataObject::get($page->ClassName)->byID($page->ID);
            $targetPage->Title = $targetTitle;
            $targetPage->Content = $targetContent;
            $targetPage->write();
        });
        FluentState::singleton()->setLocale('mi_NZ');
        return $page;
    }

    /**
     * Creates a page that only exists in the default locale draft.
     */
    private function createSourceOnlyPage(string $className, string $sourceTitle, string $sourceContent): SiteTree
    {
        $page = Versioned::withVersionedMode(function () use ($className, $sourceTitle, $sourceContent): SiteTree {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('en_NZ');
            /** @var SiteTree $page */
            $page = $className::create([
                'Title' => $sourceTitle,
                'Content' => $sourceContent,
            ]);
            $page->write();
            return DataObject::get($className)->byID($page->ID);
        });
        FluentState::singleton()->setLocale('mi_NZ');
        return $page;
    }

    /**
     * Creates an Elemental page with localised source and target HTML blocks.
     */
    private function createElementalPage(array $blocks): TranslateTestElementalPage
    {
        $page = Versioned::withVersionedMode(function () use ($blocks): TranslateTestElementalPage {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('en_NZ');
            $page = TranslateTestElementalPage::create([
                'Title' => 'Elemental page',
            ]);
            $page->write();
            foreach ($blocks as $block) {
                $page->ElementalArea()->Elements()->add(ElementContent::create([
                    'HTML' => $block,
                ]));
            }
            return DataObject::get(TranslateTestElementalPage::class)->byID($page->ID);
        });
        Versioned::withVersionedMode(function () use ($page, $blocks): void {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('mi_NZ');
            /** @var TranslateTestElementalPage $targetPage */
            $targetPage = DataObject::get(TranslateTestElementalPage::class)->byID($page->ID);
            $targetPage->Title = 'Elemental page';
            $targetPage->write();
            foreach ($targetPage->ElementalArea()->Elements()->sort('ID') as $index => $element) {
                $element->HTML = $blocks[$index];
                $element->write();
            }
        });
        FluentState::singleton()->setLocale('mi_NZ');
        return $page;
    }

    /**
     * Creates an Elemental page with localised source and target blocks of custom classes.
     */
    private function createElementalPageWithCustomBlocks(array $blocks): TranslateTestElementalPage
    {
        $page = Versioned::withVersionedMode(function () use ($blocks): TranslateTestElementalPage {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('en_NZ');
            $page = TranslateTestElementalPage::create([
                'Title' => 'Elemental page',
            ]);
            $page->write();
            foreach ($blocks as $block) {
                $className = $block['class'];
                $page->ElementalArea()->Elements()->add($className::create([
                    'HTML' => $block['html'],
                ]));
            }
            return DataObject::get(TranslateTestElementalPage::class)->byID($page->ID);
        });
        Versioned::withVersionedMode(function () use ($page, $blocks): void {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('mi_NZ');
            /** @var TranslateTestElementalPage $targetPage */
            $targetPage = DataObject::get(TranslateTestElementalPage::class)->byID($page->ID);
            $targetPage->Title = 'Elemental page';
            $targetPage->write();
            foreach ($targetPage->ElementalArea()->Elements()->sort('ID') as $index => $element) {
                $element->HTML = $blocks[$index]['html'];
                $element->write();
            }
        });
        FluentState::singleton()->setLocale('mi_NZ');
        return $page;
    }

    /**
     * Reports whether the record now exists as a draft in the supplied locale.
     */
    private function isDraftedRecordInLocale(string $className, int $id, string $locale): bool
    {
        return FluentState::singleton()->withState(
            function (FluentState $state) use ($className, $id, $locale): bool {
                $state->setLocale($locale);
                $record = DataObject::get($className)->setUseCache(false)->byID($id);
                return $record !== null && $record->isDraftedInLocale($locale);
            }
        );
    }

    /**
     * Posts a JSON apply request for the supplied page.
     */
    private function applySuggestionsJson(SiteTree $page, array $payload)
    {
        return Director::test(
            '/admin/ai-translate/apply/' . $page->ID . '?fqcn=' . rawurlencode($page->ClassName),
            [],
            $this->session(),
            'POST',
            json_encode($payload),
            [
                'Content-Type' => 'application/json',
                'X-SecurityID' => SecurityToken::inst()->getValue(),
            ]
        );
    }

    /**
     * Loads one draft-stage record in the supplied locale for assertions.
     */
    private function getDraftRecordInLocale(string $className, int $id, string $locale): ?DataObject
    {
        return FluentState::singleton()->withState(
            function (FluentState $state) use ($className, $id, $locale): ?DataObject {
                $state->setLocale($locale);
                return Versioned::withVersionedMode(function () use ($className, $id): ?DataObject {
                    Versioned::set_stage(Versioned::DRAFT);
                    return DataObject::get($className)->setUseCache(false)->byID($id);
                });
            }
        );
    }

    /**
     * Loads one Elemental block from a page in the supplied locale by list position.
     */
    private function getElementDraftRecordInLocale(int $pageId, string $locale, int $index): ?ElementContent
    {
        /** @var TranslateTestElementalPage|null $page */
        $page = $this->getDraftRecordInLocale(TranslateTestElementalPage::class, $pageId, $locale);
        if (!$page) {
            return null;
        }
        $position = 0;
        foreach ($page->ElementalArea()->Elements()->sort('ID') as $element) {
            if ($position === $index && $element instanceof ElementContent) {
                return $element;
            }
            $position++;
        }
        return null;
    }

    /**
     * Loads the first Elemental block of the supplied class from a page in one locale.
     */
    private function getDraftElementInLocale(string $className, int $pageId, string $locale): ?DataObject
    {
        /** @var TranslateTestElementalPage|null $page */
        $page = $this->getDraftRecordInLocale(TranslateTestElementalPage::class, $pageId, $locale);
        if (!$page) {
            return null;
        }
        return $page->ElementalArea()->Elements()->filter('ClassName', $className)->first();
    }

    /**
     * Flattens nested form-schema field arrays into a list of field names.
     */
    private function getSchemaFieldNames(array $fields): array
    {
        $names = [];
        foreach ($fields as $field) {
            if (isset($field['name']) && is_string($field['name'])) {
                $names[] = $field['name'];
            }
            if (!isset($field['children']) || !is_array($field['children'])) {
                continue;
            }
            $names = array_merge($names, $this->getSchemaFieldNames($field['children']));
        }
        return $names;
    }

    /**
     * Returns the logged apply skip reasons for the current request.
     */
    private function getApplySkipReasons(): array
    {
        return array_map(
            static fn(array $record): ?string => $record['context']['reason'] ?? null,
            $this->logger->records
        );
    }
}
