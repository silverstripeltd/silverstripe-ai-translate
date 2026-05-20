<?php

namespace SilverstripeLtd\AiTranslate\Tests;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

/**
 * Elemental-enabled page fixture used by translation tests.
 */
class TranslateTestElementalPage extends SiteTree implements TestOnly
{
    private static $table_name = 'AIT_ElPage';
}
