<?php

namespace SilverstripeLtd\AiTranslate\Tests;

use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\Dev\TestOnly;

/**
 * Element fixture without a frontend template.
 */
class TranslateTestUntemplatedBlock extends BaseElement implements TestOnly
{
    private static $table_name = 'AIT_UTBlock';

    private static $db = [
        'MyField' => 'Varchar(255)',
        'MyBigField' => 'Text',
    ];
}
