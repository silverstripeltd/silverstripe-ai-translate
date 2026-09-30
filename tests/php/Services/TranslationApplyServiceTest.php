<?php

namespace SilverstripeLtd\AiTranslate\Tests\Services;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\ElementContent;
use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiTranslate\Exceptions\TranslationApplyException;
use SilverstripeLtd\AiTranslate\Services\TranslationApplyService;
use SilverstripeLtd\AiTranslate\Tests\TestLogger;
use SilverstripeLtd\AiTranslate\Tests\TranslateTestElementalPage;
use SilverstripeLtd\AiTranslate\Tests\TranslateTestLockedElement;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationApplyResult;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationRewriteTarget;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Covers applying translation suggestions to target-locale draft content.
 */
class TranslationApplyServiceTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        Locale::class,
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
     * Seeds locales, an editing member, and a capturing logger.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logInWithPermission('ADMIN');
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
    }

    /**
     * Restores locale and logging state.
     */
    protected function tearDown(): void
    {
        Injector::inst()->registerService($this->originalLogger, LoggerInterface::class);
        Locale::clearCached();
        FluentState::singleton()->setLocale(null);
        parent::tearDown();
    }

    /**
     * Confirms page suggestions land on the target-locale draft while default and live content stay unchanged.
     */
    public function testAppliesPageSuggestionsToTargetLocaleDraftOnly(): void
    {
        $page = $this->createLocalisedPage();
        $this->publishInLocale($page, 'en_NZ');
        $result = $this->getService()->applyToDraft($page, $this->targetLocale, [
            $this->buildPageSuggestion($page, 'page:title', 'Title', 'Updated title'),
            $this->buildPageSuggestion($page, 'page:content', 'Content', '<p>Updated content</p>'),
        ]);
        $this->assertInstanceOf(TranslationApplyResult::class, $result);
        $this->assertSame(2, $result->appliedCount);
        $this->assertSame(0, $result->skippedCount);
        $this->assertSame(['page:title', 'page:content'], $result->appliedTargetKeys);
        $this->assertSame([], $result->skipped);
        $this->assertTrue($result->isReloadRequired());
        $this->assertSame(
            ['appliedCount' => 2, 'skippedCount' => 0, 'reloadRequired' => true],
            $result->toArray()
        );
        $targetDraft = $this->getRecordInLocale(SiteTree::class, $page->ID, 'mi_NZ', Versioned::DRAFT);
        $this->assertSame('Updated title', $targetDraft->Title);
        $this->assertSame('<p>Updated content</p>', $targetDraft->Content);
        $defaultDraft = $this->getRecordInLocale(SiteTree::class, $page->ID, 'en_NZ', Versioned::DRAFT);
        $this->assertSame('Default title', $defaultDraft->Title);
        $this->assertSame('<p>Default content</p>', $defaultDraft->Content);
        $defaultLive = $this->getRecordInLocale(SiteTree::class, $page->ID, 'en_NZ', Versioned::LIVE);
        $this->assertSame('Default title', $defaultLive->Title);
        $this->assertFalse($this->isPublishedInLocale($page, 'mi_NZ'));
    }

    /**
     * Confirms block suggestions only change the target-locale draft of the owned element.
     */
    public function testAppliesElementSuggestionToTargetLocaleDraftOnly(): void
    {
        $page = $this->createElementalPage(['<p>English block</p>']);
        $element = $page->ElementalArea()->Elements()->sort('ID')->first();
        $result = $this->getService()->applyToDraft($page, $this->targetLocale, [
            $this->buildElementSuggestion($element->ID, '<p>Maori block</p>'),
        ]);
        $this->assertSame(1, $result->appliedCount);
        $this->assertSame(0, $result->skippedCount);
        $targetElement = $this->getRecordInLocale(ElementContent::class, $element->ID, 'mi_NZ', Versioned::DRAFT);
        $this->assertSame('<p>Maori block</p>', $targetElement->HTML);
        $defaultElement = $this->getRecordInLocale(ElementContent::class, $element->ID, 'en_NZ', Versioned::DRAFT);
        $this->assertSame('<p>English block</p>', $defaultElement->HTML);
    }

    /**
     * Confirms plain fields lose markup and HTML fields lose unsafe markup before writing.
     */
    public function testSanitisesSuggestedContentBeforeWriting(): void
    {
        $page = $this->createLocalisedPage();
        $result = $this->getService()->applyToDraft($page, $this->targetLocale, [
            $this->buildPageSuggestion($page, 'page:title', 'Title', '<strong>Updated</strong> title'),
            $this->buildPageSuggestion(
                $page,
                'page:content',
                'Content',
                '<p onclick="alert(1)">Updated <strong>content</strong></p><script>alert(1)</script>'
            ),
        ]);
        $this->assertSame(2, $result->appliedCount);
        $targetDraft = $this->getRecordInLocale(SiteTree::class, $page->ID, 'mi_NZ', Versioned::DRAFT);
        $this->assertSame('Updated title', $targetDraft->Title);
        $this->assertSame('<p>Updated <strong>content</strong></p>', $targetDraft->Content);
    }

    /**
     * Confirms invalid, unselected, duplicate and unknown entries are skipped with stable reasons.
     */
    public function testSkipsInvalidUnknownAndDuplicateSuggestions(): void
    {
        $page = $this->createLocalisedPage();
        $result = $this->getService()->applyToDraft($page, $this->targetLocale, [
            'not an array',
            $this->buildPageSuggestion($page, 'page:title', 'Title', 'Unselected title', false),
            $this->buildPageSuggestion($page, 'page:title', 'Title', 'Updated title'),
            $this->buildPageSuggestion($page, 'page:title', 'Title', 'Duplicate title'),
            $this->buildPageSuggestion($page, 'page:missing', 'Title', 'Unknown target'),
            $this->buildPageSuggestion($page, 'page:content', 'Title', '<p>Mismatched field</p>'),
            ['apply' => true, 'targetKey' => '', 'suggestedContent' => 'No key'],
            ['apply' => true, 'targetKey' => 'page:content', 'suggestedContent' => null],
        ]);
        $this->assertSame(1, $result->appliedCount);
        $this->assertSame(6, $result->skippedCount);
        $this->assertSame(['page:title'], $result->appliedTargetKeys);
        $this->assertSame(
            [
                'invalid-payload',
                'duplicate-target',
                'mismatched-target',
                'target-metadata-mismatch',
                'missing-target-key',
                'missing-suggested-content',
            ],
            array_column($result->skipped, 'reason')
        );
        $this->assertSame([0, 3, 4, 5, 6, 7], array_column($result->skipped, 'index'));
        $this->assertContains('duplicate-target', $this->getLoggedSkipReasons());
        $targetDraft = $this->getRecordInLocale(SiteTree::class, $page->ID, 'mi_NZ', Versioned::DRAFT);
        $this->assertSame('Updated title', $targetDraft->Title);
        $this->assertSame('<p>Current</p>', $targetDraft->Content);
    }

    /**
     * Confirms an empty selection applies nothing and needs no reload.
     */
    public function testEmptySuggestionsApplyNothing(): void
    {
        $page = $this->createLocalisedPage();
        $result = $this->getService()->applyToDraft($page, $this->targetLocale, []);
        $this->assertSame(0, $result->appliedCount);
        $this->assertSame(0, $result->skippedCount);
        $this->assertFalse($result->isReloadRequired());
        $targetDraft = $this->getRecordInLocale(SiteTree::class, $page->ID, 'mi_NZ', Versioned::DRAFT);
        $this->assertSame('Current title', $targetDraft->Title);
    }

    /**
     * Confirms records without a target-locale draft are rejected before anything is written.
     */
    public function testRejectsRecordNotLocalisedInTargetLocale(): void
    {
        $page = $this->createSourceOnlyPage('Default title', '<p>Default content</p>');
        try {
            $this->getService()->applyToDraft($page, $this->targetLocale, [
                $this->buildPageSuggestion($page, 'page:title', 'Title', 'Maori title'),
            ]);
            $this->fail();
        } catch (TranslationApplyException $exception) {
            $this->assertSame(TranslationApplyException::REASON_NOT_LOCALISED, $exception->getReason());
            $this->assertSame(TranslationApplyService::LOCALISED_RECORD_REQUIRED_MESSAGE, $exception->getMessage());
        }
        $this->assertFalse($this->isDraftedInLocale($page, 'mi_NZ'));
        $defaultDraft = $this->getRecordInLocale(SiteTree::class, $page->ID, 'en_NZ', Versioned::DRAFT);
        $this->assertSame('Default title', $defaultDraft->Title);
    }

    /**
     * Confirms the default locale is never a valid apply target.
     */
    public function testRejectsDefaultLocaleTarget(): void
    {
        $page = $this->createLocalisedPage();
        $this->expectException(TranslationApplyException::class);
        $this->expectExceptionMessage(TranslationApplyService::NON_DEFAULT_LOCALE_REQUIRED_MESSAGE);
        $this->getService()->applyToDraft($page, $this->defaultLocale, [
            $this->buildPageSuggestion($page, 'page:title', 'Title', 'Updated title'),
        ]);
    }

    /**
     * Confirms a block that cannot be edited fails the whole apply before any write happens.
     */
    public function testThrowsWhenSelectedElementCannotBeEdited(): void
    {
        $page = $this->createElementalPage(['<p>Locked block</p>'], TranslateTestLockedElement::class);
        $element = $page->ElementalArea()->Elements()->sort('ID')->first();
        try {
            $this->getService()->applyToDraft($page, $this->targetLocale, [
                $this->buildPageSuggestion($page, 'page:title', 'Title', 'Updated title'),
                $this->buildElementSuggestion($element->ID, '<p>Updated locked block</p>'),
            ]);
            $this->fail();
        } catch (TranslationApplyException $exception) {
            $this->assertSame(TranslationApplyException::REASON_PERMISSION_DENIED, $exception->getReason());
        }
        $targetPage = $this->getRecordInLocale(TranslateTestElementalPage::class, $page->ID, 'mi_NZ', Versioned::DRAFT);
        $this->assertSame('Elemental page', $targetPage->Title);
        $targetElement = $this->getRecordInLocale(
            TranslateTestLockedElement::class,
            $element->ID,
            'mi_NZ',
            Versioned::DRAFT
        );
        $this->assertSame('<p>Locked block</p>', $targetElement->HTML);
    }

    /**
     * Returns the service under test.
     */
    private function getService(): TranslationApplyService
    {
        return Injector::inst()->get(TranslationApplyService::class);
    }

    /**
     * Builds one page-level suggestion payload entry.
     */
    private function buildPageSuggestion(
        SiteTree $page,
        string $targetKey,
        string $fieldName,
        string $suggestedContent,
        bool $apply = true
    ): array {
        return [
            'apply' => $apply,
            'targetKey' => $targetKey,
            'targetType' => $fieldName === 'Title'
                ? TranslationRewriteTarget::TYPE_PAGE_TITLE
                : TranslationRewriteTarget::TYPE_PAGE_CONTENT,
            'targetId' => $page->ID,
            'fieldName' => $fieldName,
            'suggestedContent' => $suggestedContent,
        ];
    }

    /**
     * Builds one Elemental HTML suggestion payload entry.
     */
    private function buildElementSuggestion(int $elementId, string $suggestedContent): array
    {
        return [
            'apply' => true,
            'targetKey' => sprintf('element:%d:html', $elementId),
            'targetType' => TranslationRewriteTarget::TYPE_ELEMENT_HTML,
            'targetId' => $elementId,
            'fieldName' => 'HTML',
            'suggestedContent' => $suggestedContent,
        ];
    }

    /**
     * Creates a page with separate source and target locale draft content.
     */
    private function createLocalisedPage(): SiteTree
    {
        $targetTitle = 'Current title';
        $targetContent = '<p>Current</p>';
        $page = $this->createSourceOnlyPage('Default title', '<p>Default content</p>');
        Versioned::withVersionedMode(function () use ($page, $targetTitle, $targetContent): void {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('mi_NZ');
            $targetPage = DataObject::get(SiteTree::class)->byID($page->ID);
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
    private function createSourceOnlyPage(string $sourceTitle, string $sourceContent): SiteTree
    {
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
        FluentState::singleton()->setLocale('mi_NZ');
        return $page;
    }

    /**
     * Creates an Elemental page whose blocks are localised in both locales.
     */
    private function createElementalPage(
        array $blocks,
        string $blockClass = ElementContent::class
    ): TranslateTestElementalPage {
        $page = Versioned::withVersionedMode(function () use ($blocks, $blockClass): TranslateTestElementalPage {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('en_NZ');
            $page = TranslateTestElementalPage::create(['Title' => 'Elemental page']);
            $page->write();
            foreach ($blocks as $block) {
                $page->ElementalArea()->Elements()->add($blockClass::create(['HTML' => $block]));
            }
            return DataObject::get(TranslateTestElementalPage::class)->byID($page->ID);
        });
        Versioned::withVersionedMode(function () use ($page, $blocks): void {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('mi_NZ');
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
     * Publishes the page in one locale.
     */
    private function publishInLocale(SiteTree $page, string $locale): void
    {
        FluentState::singleton()->withState(function (FluentState $state) use ($page, $locale): void {
            $state->setLocale($locale);
            $record = DataObject::get($page->ClassName)->setUseCache(false)->byID($page->ID);
            $record->publishRecursive();
        });
    }

    /**
     * Loads one record from a stage in the supplied locale.
     */
    private function getRecordInLocale(string $className, int $id, string $locale, string $stage): ?DataObject
    {
        return FluentState::singleton()->withState(
            function (FluentState $state) use ($className, $id, $locale, $stage): ?DataObject {
                $state->setLocale($locale);
                return Versioned::withVersionedMode(function () use ($className, $id, $stage): ?DataObject {
                    Versioned::set_stage($stage);
                    return DataObject::get($className)->setUseCache(false)->byID($id);
                });
            }
        );
    }

    /**
     * Reports whether the page has a draft row in the supplied locale.
     */
    private function isDraftedInLocale(SiteTree $page, string $locale): bool
    {
        $record = $this->getRecordInLocale($page->ClassName, $page->ID, $locale, Versioned::DRAFT);
        return $record !== null && $record->isDraftedInLocale($locale);
    }

    /**
     * Reports whether the page has a live row in the supplied locale.
     */
    private function isPublishedInLocale(SiteTree $page, string $locale): bool
    {
        $record = $this->getRecordInLocale($page->ClassName, $page->ID, $locale, Versioned::DRAFT);
        return $record !== null && $record->isPublishedInLocale($locale);
    }

    /**
     * Returns the skip reasons written to the logger.
     */
    private function getLoggedSkipReasons(): array
    {
        return array_map(
            static fn(array $record): ?string => $record['context']['reason'] ?? null,
            $this->logger->records
        );
    }
}
