<?php

namespace SilverstripeLtd\AiTranslate\Tests;

use DNADesign\Elemental\Models\ElementContent;
use SilverStripe\Dev\TestOnly;

/**
 * Element fixture that cannot be mutated through interactive apply.
 */
class TranslateTestLockedElement extends ElementContent implements TestOnly
{
    private static $table_name = 'AIT_LockedEl';

    public function canEdit($member = null): bool
    {
        return false;
    }
}
