<?php

namespace SilverstripeLtd\AiTranslate\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Adds translation toolbar context to editable non-default locale records.
 */
class AiTranslateExtension extends Extension
{
    /**
     * Adds the hidden record-class field used by the CMS button adapter.
     */
    public function updateCMSFields(FieldList $fields): void
    {
        if (!$this->owner->exists() || !$this->owner->canEdit()) {
            return;
        }
        if (!$this->canAiTranslateInCurrentLocale()) {
            return;
        }
        if ($fields->dataFieldByName('AiTranslateRecordClass')) {
            return;
        }
        $fields->push(HiddenField::create(
            'AiTranslateRecordClass',
            null,
            $this->owner->ClassName
        ));
    }

    /**
     * Reports whether AI Translate is available for the current Fluent locale.
     */
    public function canAiTranslateInCurrentLocale(): bool
    {
        return $this->canAiTranslateInLocale((string) FluentState::singleton()->getLocale());
    }

    /**
     * Reports whether AI Translate is available for one locale on this record.
     */
    public function canAiTranslateInLocale(string $localeCode): bool
    {
        if ($localeCode === '') {
            return false;
        }
        if ($this->isDefaultLocale($localeCode)) {
            return false;
        }
        if ($this->owner->hasMethod('isDraftedInLocale')) {
            return (bool) $this->owner->isDraftedInLocale($localeCode);
        }
        if ($this->owner->hasMethod('existsInLocale')) {
            return (bool) $this->owner->existsInLocale($localeCode);
        }
        return false;
    }

    /**
     * Reports whether one locale is Fluent's default locale.
     */
    private function isDefaultLocale(string $localeCode): bool
    {
        $defaultLocale = Locale::getDefault();
        if (!$defaultLocale || !$defaultLocale->Locale) {
            return false;
        }
        return $localeCode === (string) $defaultLocale->Locale;
    }
}
