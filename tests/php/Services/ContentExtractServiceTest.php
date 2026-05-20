<?php

namespace SilverstripeLtd\AiTranslate\Tests\Services;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\ElementContent;
use SilverstripeLtd\AiTranslate\Services\ContentExtractService;
use SilverstripeLtd\AiTranslate\Tests\TranslateTestElementalPage;
use SilverstripeLtd\AiTranslate\Tests\TranslateTestExtractionExtension;
use SilverstripeLtd\AiTranslate\Tests\TranslateTestHiddenElement;
use SilverstripeLtd\AiTranslate\Tests\TranslateTestUntemplatedBlock;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationRewriteTarget;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Covers dual-locale extraction and Elemental target discovery.
 */
class ContentExtractServiceTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        Locale::class,
        TranslateTestElementalPage::class,
        TranslateTestHiddenElement::class,
        TranslateTestUntemplatedBlock::class,
        ElementContent::class,
    ];

    protected static $required_extensions = [
        TranslateTestElementalPage::class => [
            ElementalPageExtension::class,
        ],
    ];

    private Locale $defaultLocale;
    private Locale $targetLocale;

    /**
     * Seeds Fluent locales for extraction tests.
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
        FluentState::singleton()->setLocale($this->defaultLocale->Locale);
    }

    /**
     * Clears locale and extension state after extraction tests.
     */
    protected function tearDown(): void
    {
        Config::modify()->set(ContentExtractService::class, 'extensions', []);
        Locale::clearCached();
        FluentState::singleton()->setLocale(null);
        parent::tearDown();
    }

    /**
     * Confirms non-Elemental pages produce matching page-level targets across locales.
     */
    public function testExtractBuildsStructuredPageTargetsForBothLocales(): void
    {
        $page = $this->createLocalisedPage(
            SiteTree::class,
            'Default title',
            '<p>Default <strong>content</strong></p>',
            '',
            ''
        );
        $service = new ContentExtractService();
        $result = $service->extract($page, $this->defaultLocale, $this->targetLocale);
        $this->assertSame(
            "Default title\n\nDefault *content*",
            $result->sourceContent
        );
        $this->assertCount(2, $result->sourceRewriteTargets);
        $this->assertSame('page:title', $result->sourceRewriteTargets[0]->targetKey);
        $this->assertSame('page:content', $result->sourceRewriteTargets[1]->targetKey);
        $this->assertSame(
            '<p>Default <strong>content</strong></p>',
            $result->sourceRewriteTargets[1]->content
        );
        $this->assertSame('html', $result->sourceRewriteTargets[1]->contentFormat);
        $this->assertCount(2, $result->targetRewriteTargets);
        $this->assertSame('page:title', $result->targetRewriteTargets[0]->targetKey);
        $this->assertSame('Untitled Page', $result->targetRewriteTargets[0]->content);
        $this->assertSame('page:content', $result->targetRewriteTargets[1]->targetKey);
        $this->assertSame('', $result->targetRewriteTargets[1]->content);
        $this->assertSame('Page name', $result->sourceRewriteTargets[0]->fieldLabel);
    }

    /**
     * Confirms Elemental extraction keeps stable keys, empty target values, and extension hooks.
     */
    public function testExtractBuildsElementTargetsAndAppliesHooks(): void
    {
        Config::modify()->merge(ContentExtractService::class, 'extensions', [
            TranslateTestExtractionExtension::class,
        ]);
        $page = Versioned::withVersionedMode(function (): TranslateTestElementalPage {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('en_NZ');
            $page = TranslateTestElementalPage::create([
                'Title' => 'Elemental title',
            ]);
            $page->write();
            $page->ElementalArea()->Elements()->add(TranslateTestUntemplatedBlock::create([
                'Title' => 'Hidden source heading',
                'MyField' => 'Source intro',
                'MyBigField' => 'Source supporting copy',
            ]));
            $page->ElementalArea()->Elements()->add(ElementContent::create([
                'Title' => 'Second hidden heading',
                'HTML' => '<p>Source <strong>HTML</strong> block</p>',
            ]));
            return DataObject::get(TranslateTestElementalPage::class)->byID($page->ID);
        });
        Versioned::withVersionedMode(function () use ($page): void {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('mi_NZ');
            /** @var TranslateTestElementalPage $targetPage */
            $targetPage = DataObject::get(TranslateTestElementalPage::class)->byID($page->ID);
            $targetPage->Title = 'Target elemental title';
            $targetPage->write();
            /** @var TranslateTestUntemplatedBlock $firstElement */
            $firstElement = $targetPage->ElementalArea()->Elements()->sort('ID')->first();
            $firstElement->Title = 'Translated hidden heading';
            $firstElement->MyField = 'Current translated intro';
            $firstElement->MyBigField = 'Current translated supporting copy';
            $firstElement->write();
            /** @var ElementContent $lastElement */
            $lastElement = $targetPage->ElementalArea()->Elements()->sort('ID')->last();
            $lastElement->Title = 'Second translated heading';
            $lastElement->HTML = '<p>Current translated HTML block</p>';
            $lastElement->write();
        });
        Versioned::withVersionedMode(function () use ($page): void {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('en_NZ');
            /** @var TranslateTestElementalPage $sourcePage */
            $sourcePage = DataObject::get(TranslateTestElementalPage::class)->byID($page->ID);
            $sourcePage->Title = 'Elemental title';
            $sourcePage->write();
            /** @var TranslateTestUntemplatedBlock $firstElement */
            $firstElement = $sourcePage->ElementalArea()->Elements()->sort('ID')->first();
            $firstElement->Title = 'Hidden source heading';
            $firstElement->MyField = 'Source intro';
            $firstElement->MyBigField = 'Source supporting copy';
            $firstElement->write();
            /** @var ElementContent $lastElement */
            $lastElement = $sourcePage->ElementalArea()->Elements()->sort('ID')->last();
            $lastElement->Title = 'Second hidden heading';
            $lastElement->HTML = '<p>Source <strong>HTML</strong> block</p>';
            $lastElement->write();
        });
        $service = new ContentExtractService();
        $result = $service->extract($page, $this->defaultLocale, $this->targetLocale);
        $this->assertStringContainsString('Elemental title', $result->sourceContent);
        $this->assertStringContainsString('Source intro', $result->sourceContent);
        $this->assertStringContainsString('Source supporting copy', $result->sourceContent);
        $this->assertStringContainsString('Appended from extension', $result->sourceContent);
        $sourceKeys = array_map(
            static fn(TranslationRewriteTarget $target): string => $target->targetKey,
            $result->sourceRewriteTargets
        );
        $this->assertSame('page:title', $sourceKeys[0]);
        $this->assertStringStartsWith('element:', $sourceKeys[1]);
        $this->assertStringStartsWith('element:', $sourceKeys[2]);
        $this->assertStringStartsWith('element:', $sourceKeys[3]);
        $this->assertStringStartsWith('element:', $sourceKeys[4]);
        $this->assertContains('extension:summary', $sourceKeys);
        $sourceTitleTargets = array_values(array_filter(
            $result->sourceRewriteTargets,
            static fn(TranslationRewriteTarget $target): bool => $target->fieldName === 'Title'
                && $target->targetKey !== 'page:title'
        ));
        $this->assertCount(2, $sourceTitleTargets);
        $this->assertSame(
            ['Hidden source heading', 'Second hidden heading'],
            array_map(static fn(TranslationRewriteTarget $target): string => $target->content, $sourceTitleTargets)
        );
        $sourceIntroTargets = array_values(array_filter(
            $result->sourceRewriteTargets,
            static fn(TranslationRewriteTarget $target): bool => $target->fieldName === 'MyField'
        ));
        $this->assertSame('My field', $sourceIntroTargets[0]->fieldLabel);
        $this->assertSame('Hidden source heading', $sourceIntroTargets[0]->targetTitle);
        $this->assertSame('Source intro', $sourceIntroTargets[0]->content);
        $this->assertSame('text', $sourceIntroTargets[0]->contentFormat);
        $sourceHtmlTargets = array_values(array_filter(
            $result->sourceRewriteTargets,
            static fn(TranslationRewriteTarget $target): bool => $target->fieldName === 'HTML'
        ));
        $this->assertSame('<p>Source <strong>HTML</strong> block</p>', $sourceHtmlTargets[0]->content);
        $this->assertSame('html', $sourceHtmlTargets[0]->contentFormat);
        $this->assertSame('Target elemental title', $result->targetRewriteTargets[0]->content);
        $targetTitleTargets = array_values(array_filter(
            $result->targetRewriteTargets,
            static fn(TranslationRewriteTarget $target): bool => $target->fieldName === 'Title'
                && $target->targetKey !== 'page:title'
        ));
        $this->assertCount(2, $targetTitleTargets);
        $this->assertSame(
            ['Translated hidden heading', 'Second translated heading'],
            array_map(static fn(TranslationRewriteTarget $target): string => $target->content, $targetTitleTargets)
        );
        $targetIntroTargets = array_values(array_filter(
            $result->targetRewriteTargets,
            static fn(TranslationRewriteTarget $target): bool => $target->fieldName === 'MyField'
        ));
        $this->assertSame('Current translated intro', $targetIntroTargets[0]->content);
        $targetSupportingTargets = array_values(array_filter(
            $result->targetRewriteTargets,
            static fn(TranslationRewriteTarget $target): bool => $target->fieldName === 'MyBigField'
        ));
        $this->assertSame('Current translated supporting copy', $targetSupportingTargets[0]->content);
        $targetHtmlTargets = array_values(array_filter(
            $result->targetRewriteTargets,
            static fn(TranslationRewriteTarget $target): bool => $target->fieldName === 'HTML'
        ));
        $this->assertSame('<p>Current translated HTML block</p>', $targetHtmlTargets[0]->content);
        $extensionTargets = array_values(array_filter(
            $result->targetRewriteTargets,
            static fn(TranslationRewriteTarget $target): bool => $target->targetKey === 'extension:summary'
        ));
        $this->assertSame('', $extensionTargets[0]->content);
    }

    /**
     * Confirms interactive translation extraction excludes Elemental blocks that fail canView().
     */
    public function testExtractExcludesHiddenElementTargets(): void
    {
        $page = $this->createLocalisedElementalPage([
            [
                'class' => ElementContent::class,
                'html' => '<p>Visible source block</p>',
            ],
            [
                'class' => TranslateTestHiddenElement::class,
                'html' => '<p>Hidden source block</p>',
            ],
        ], [
            '<p>Visible target block</p>',
            '<p>Hidden target block</p>',
        ]);
        $service = new ContentExtractService();

        $result = $service->extract($page, $this->defaultLocale, $this->targetLocale);
        $sourceKeys = array_map(
            static fn(TranslationRewriteTarget $target): string => $target->targetKey,
            $result->sourceRewriteTargets
        );
        $targetKeys = array_map(
            static fn(TranslationRewriteTarget $target): string => $target->targetKey,
            $result->targetRewriteTargets
        );
        $sourceContents = array_map(
            static fn(TranslationRewriteTarget $target): string => $target->content,
            $result->sourceRewriteTargets
        );
        $targetContents = array_map(
            static fn(TranslationRewriteTarget $target): string => $target->content,
            $result->targetRewriteTargets
        );

        $this->assertCount(2, $result->sourceRewriteTargets);
        $this->assertCount(2, $result->targetRewriteTargets);
        $this->assertSame('page:title', $sourceKeys[0]);
        $this->assertSame('page:title', $targetKeys[0]);
        $this->assertStringStartsWith('element:', $sourceKeys[1]);
        $this->assertSame($sourceKeys[1], $targetKeys[1]);
        $this->assertContains('<p>Visible source block</p>', $sourceContents);
        $this->assertContains('<p>Visible target block</p>', $targetContents);
        $this->assertNotContains('<p>Hidden source block</p>', $sourceContents);
        $this->assertNotContains('<p>Hidden target block</p>', $targetContents);
    }

    /**
     * Creates a page with source and target locale content.
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
        FluentState::singleton()->setLocale('en_NZ');
        return $page;
    }

    /**
     * Creates a localised Elemental page with matching source and target block definitions.
     */
    private function createLocalisedElementalPage(array $sourceBlocks, array $targetBlocks): TranslateTestElementalPage
    {
        $page = Versioned::withVersionedMode(function () use ($sourceBlocks): TranslateTestElementalPage {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('en_NZ');
            $page = TranslateTestElementalPage::create([
                'Title' => 'Elemental title',
            ]);
            $page->write();
            foreach ($sourceBlocks as $block) {
                $className = $block['class'] ?? ElementContent::class;
                $page->ElementalArea()->Elements()->add($className::create([
                    'HTML' => $block['html'],
                ]));
            }
            return DataObject::get(TranslateTestElementalPage::class)->byID($page->ID);
        });
        Versioned::withVersionedMode(function () use ($page, $targetBlocks): void {
            Versioned::set_stage(Versioned::DRAFT);
            FluentState::singleton()->setLocale('mi_NZ');
            /** @var TranslateTestElementalPage $targetPage */
            $targetPage = DataObject::get(TranslateTestElementalPage::class)->byID($page->ID);
            $targetPage->Title = 'Target elemental title';
            $targetPage->write();
            foreach ($targetPage->ElementalArea()->Elements()->sort('ID') as $index => $element) {
                $element->HTML = $targetBlocks[$index];
                $element->write();
            }
        });
        FluentState::singleton()->setLocale('en_NZ');
        return $page;
    }
}
