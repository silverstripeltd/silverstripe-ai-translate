<?php

namespace SilverstripeLtd\AiTranslate\Tests\Extensions;

use SilverstripeLtd\AiTranslate\Tests\RestrictedTranslatePage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\HiddenField;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Covers extension behaviour for AI translate CMS toolbar context.
 */
class AiTranslateExtensionTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        Locale::class,
        RestrictedTranslatePage::class,
    ];

    private Locale $defaultLocale;
    private Locale $targetLocale;

    /**
     * Seed locales and permissions for extension tests.
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
     * Reset locale state after extension tests.
     */
    protected function tearDown(): void
    {
        Locale::clearCached();
        FluentState::singleton()->setLocale(null);
        parent::tearDown();
    }

    /**
     * Ensure editable records in non-default locales expose preview toolbar context.
     */
    public function testUpdateCMSFieldsAddsToolbarContextForEditableNonDefaultLocale(): void
    {
        FluentState::singleton()->setLocale($this->targetLocale->Locale);
        $page = SiteTree::create(['Title' => 'Toolbar test']);
        $page->write();
        $this->assertTrue($page->canAiTranslateInCurrentLocale());
        $fields = $page->getCMSFields();
        $actions = $page->getCMSActions();
        $recordClass = $fields->dataFieldByName('AiTranslateRecordClass');
        $this->assertInstanceOf(HiddenField::class, $recordClass);
        $this->assertSame($page->ClassName, $recordClass->dataValue());
        $this->assertNull($actions->fieldByName('MajorActions')->fieldByName('action_AiTranslateAction'));
    }

    /**
     * Ensure default locale records do not expose preview toolbar context.
     */
    public function testUpdateCMSFieldsSkipsToolbarContextInDefaultLocale(): void
    {
        FluentState::singleton()->setLocale($this->defaultLocale->Locale);
        $page = SiteTree::create(['Title' => 'Default locale test']);
        $page->write();
        $this->assertFalse($page->canAiTranslateInCurrentLocale());
        $fields = $page->getCMSFields();
        $this->assertNull($fields->dataFieldByName('AiTranslateRecordClass'));
    }

    /**
     * Ensure pages without localised draft content do not expose toolbar context.
     */
    public function testUpdateCMSFieldsSkipsToolbarContextWhenCurrentLocaleHasNoLocalisedDraft(): void
    {
        FluentState::singleton()->setLocale($this->defaultLocale->Locale);
        $page = SiteTree::create(['Title' => 'Source only locale test']);
        $page->write();
        FluentState::singleton()->setLocale($this->targetLocale->Locale);
        /** @var SiteTree $targetPage */
        $targetPage = SiteTree::get()->setUseCache(false)->byID($page->ID);
        $this->assertFalse($targetPage->canAiTranslateInCurrentLocale());
        $fields = $targetPage->getCMSFields();
        $actions = $targetPage->getCMSActions();
        $this->assertNull($fields->dataFieldByName('AiTranslateRecordClass'));
        $this->assertNull($actions->fieldByName('MajorActions')->fieldByName('action_AiTranslateAction'));
    }

    /**
     * Ensure non-editable records do not expose preview toolbar context.
     */
    public function testUpdateCMSFieldsSkipsToolbarContextWhenRecordCannotEdit(): void
    {
        FluentState::singleton()->setLocale($this->targetLocale->Locale);
        $page = RestrictedTranslatePage::create(['Title' => 'Restricted locale test']);
        $page->write();
        $fields = $page->getCMSFields();
        $actions = $page->getCMSActions();
        $this->assertNull($fields->dataFieldByName('AiTranslateRecordClass'));
        $this->assertNull($actions->fieldByName('MajorActions')->fieldByName('action_AiTranslateAction'));
    }

    /**
     * Ensure updateCMSFields does not push duplicate toolbar context fields.
     */
    public function testUpdateCMSFieldsAvoidsDuplicateToolbarContextField(): void
    {
        FluentState::singleton()->setLocale($this->targetLocale->Locale);
        $page = SiteTree::create(['Title' => 'Duplicate field test']);
        $page->write();
        $fields = $page->getCMSFields();
        $page->extend('updateCMSFields', $fields);
        $matches = array_filter(
            $fields->dataFields(),
            fn ($field): bool => $field->getName() === 'AiTranslateRecordClass'
        );
        $this->assertCount(1, $matches);
    }
}
