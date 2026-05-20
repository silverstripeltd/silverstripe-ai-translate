<?php

namespace SilverstripeLtd\AiTranslate\Tests\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiTranslate\Services\LocaleLabelService;
use TractorCow\Fluent\Model\Locale;

/**
 * Covers LocaleLabelService modal label formatting.
 */
class LocaleLabelServiceTest extends SapphireTest
{
    protected $usesDatabase = false;

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function provideModalLanguageLabel(): array
    {
        return [
            'simple title' => ['Te Reo Maori', 'mi_NZ', 'te reo maori'],
            'title with parenthetical' => ['English (New Zealand)', 'en_NZ', 'english'],
            'empty title falls back to locale code' => ['', 'en_NZ', 'en_nz'],
            'whitespace title falls back to locale code' => ['   ', 'fr_FR', 'fr_fr'],
            'title with trailing spaces' => ['  Deutsch  ', 'de_DE', 'deutsch'],
        ];
    }

    #[DataProvider('provideModalLanguageLabel')]
    public function testGetModalLanguageLabel(string $title, string $localeCode, string $expected): void
    {
        $locale = Locale::create(['Title' => $title, 'Locale' => $localeCode]);
        $service = new LocaleLabelService();
        $this->assertSame($expected, $service->getModalLanguageLabel($locale));
    }
}
