<?php

namespace SilverstripeLtd\AiTranslate\Extensions;

use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;
use TractorCow\Fluent\Extension\FluentVersionedExtension;

/**
 * Keeps Fluent's Elemental locale storage while removing unsupported CMS locale controls.
 */
class ElementalFluentVersionedExtension extends FluentVersionedExtension
{
    /**
     * Adds Fluent field decoration without the locale grid that breaks Elemental form schema.
     */
    protected function updateCMSFields(FieldList $fields): void
    {
        parent::updateCMSFields($fields);
        $this->removeUnsupportedLocalisationFields($fields);
    }

    /**
     * Removes Fluent localisation fields that cannot render inside Elemental block edit forms.
     */
    private function removeUnsupportedLocalisationFields(FieldList $fields): void
    {
        $fields->removeByName('RecordLocales', true);
        $root = $fields->fieldByName('Root');
        if (!$root instanceof CompositeField) {
            return;
        }
        $root->removeByName('Locales');
    }
}
