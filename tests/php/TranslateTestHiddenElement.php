<?php

namespace SilverstripeLtd\AiTranslate\Tests;

use DNADesign\Elemental\Models\ElementContent;
use SilverStripe\Dev\TestOnly;

/**
 * Element fixture hidden from interactive translation targets.
 */
class TranslateTestHiddenElement extends ElementContent implements TestOnly
{
    private static $table_name = 'AIT_HiddenEl';

    public function canView($member = null): bool
    {
        return false;
    }
}
