<?php

namespace SilverstripeLtd\AiTranslate\Services;

use TractorCow\Fluent\Model\Locale;

/**
 * Formats Fluent locales into human-readable labels.
 */
class LocaleLabelService
{
    /**
     * Builds the display label for one locale.
     */
    public function getLanguageLabel(Locale $locale): string
    {
        $title = trim((string) $locale->getField('Title'));
        $code = trim((string) $locale->getField('Locale'));
        if ($title === '') {
            return $code;
        }
        if ($code === '') {
            return $title;
        }
        if (stripos($title, $code) !== false) {
            return $title;
        }
        if (strpos($title, '(') !== false || strpos($title, ')') !== false) {
            return $title;
        }
        if (preg_match('/\s/', $title)) {
            return $title;
        }
        return sprintf('%s (%s)', $title, $code);
    }

    /**
     * Builds the shorter, lower-case label used in modal headings.
     */
    public function getModalLanguageLabel(Locale $locale): string
    {
        $label = trim((string) $locale->getField('Title'));
        if ($label === '') {
            $label = trim((string) $locale->getField('Locale'));
        }

        $label = preg_replace('/\s*\([^)]*\)\s*$/', '', $label) ?: $label;
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($label);
        }
        return strtolower($label);
    }
}
