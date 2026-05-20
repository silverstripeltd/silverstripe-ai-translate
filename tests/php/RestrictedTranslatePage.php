<?php

namespace SilverstripeLtd\AiTranslate\Tests;

use SilverStripe\CMS\Model\SiteTree;

/**
 * Simple page type that denies edit access for extension tests.
 */
class RestrictedTranslatePage extends SiteTree
{
    private static string $table_name = 'AITestRestrPg';

    /**
     * Ensure toolbar context is hidden when editing is not allowed.
     */
    public function canEdit($member = null): bool
    {
        return false;
    }
}
