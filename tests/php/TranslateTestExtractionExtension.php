<?php

namespace SilverstripeLtd\AiTranslate\Tests;

use SilverstripeLtd\AiTranslate\Services\ContentExtractService;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationRewriteTarget;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use TractorCow\Fluent\Model\Locale;

/**
 * Test extension that mutates extracted translation content.
 */
class TranslateTestExtractionExtension extends Extension
{
    /**
     * Appends extra text to the extracted source payload.
     */
    public function updateExtractedContent(string &$content, DataObject $record, Locale $locale): void
    {
        if ((string) $locale->Locale === 'en_NZ') {
            $content .= "\n\nAppended from extension";
        }
    }

    /**
     * Adds one extension-provided rewrite target.
     */
    public function updateExtractedRewriteTargets(array &$targets, DataObject $record, Locale $locale): void
    {
        if ((string) $locale->Locale !== 'en_NZ') {
            return;
        }
        $targets[] = new TranslationRewriteTarget(
            'extension:summary',
            TranslationRewriteTarget::TYPE_PAGE_CONTENT,
            'Content',
            $record->exists() ? (int) $record->ID : null,
            'Extension summary',
            'Extension provided target',
            '<p>Extension supplied summary</p>',
            'html'
        );
    }
}
