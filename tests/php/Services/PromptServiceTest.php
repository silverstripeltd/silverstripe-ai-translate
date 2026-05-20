<?php

namespace SilverstripeLtd\AiTranslate\Tests\Services;

use SilverstripeLtd\AiTranslate\Services\PromptService;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationExtractedContent;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationRewriteTarget;
use SilverStripe\Dev\SapphireTest;
use TractorCow\Fluent\Model\Locale;

/**
 * Covers prompt-template loading and target serialisation.
 */
class PromptServiceTest extends SapphireTest
{
    /**
     * Confirms prompt templates embed locale labels and dual-locale rewrite-target JSON.
     */
    public function testBuildPromptsLoadsTemplatesAndSerialisesTargets(): void
    {
        $sourceLocale = Locale::create([
            'Title' => 'English',
            'Locale' => 'en_NZ',
        ]);
        $targetLocale = Locale::create([
            'Title' => 'Te Reo Maori',
            'Locale' => 'mi_NZ',
        ]);
        $service = new PromptService();
        [$systemPrompt, $userPrompt] = $service->buildPrompts(
            new TranslationExtractedContent(
                'About us Welcome to our website.',
                [
                    new TranslationRewriteTarget(
                        'page:title',
                        TranslationRewriteTarget::TYPE_PAGE_TITLE,
                        'Title',
                        15,
                        'Page name',
                        '',
                        'About us',
                        'text'
                    ),
                    new TranslationRewriteTarget(
                        'element:42:html',
                        TranslationRewriteTarget::TYPE_ELEMENT_HTML,
                        'HTML',
                        42,
                        'HTML',
                        'Content',
                        '<p>Welcome to our website.</p>',
                        'html'
                    ),
                ],
                [
                    new TranslationRewriteTarget(
                        'page:title',
                        TranslationRewriteTarget::TYPE_PAGE_TITLE,
                        'Title',
                        15,
                        'Page name',
                        '',
                        'Uber uns',
                        'text'
                    ),
                    new TranslationRewriteTarget(
                        'element:42:html',
                        TranslationRewriteTarget::TYPE_ELEMENT_HTML,
                        'HTML',
                        42,
                        'HTML',
                        'Content',
                        '<p>Willkommen auf unserer Website.</p>',
                        'html'
                    ),
                ]
            ),
            $sourceLocale,
            $targetLocale
        );
        $this->assertStringContainsString('Return only valid JSON', $systemPrompt);
        $this->assertStringContainsString('English (en_NZ)', $userPrompt);
        $this->assertStringContainsString('Te Reo Maori', $userPrompt);
        $this->assertStringContainsString('=== REWRITE_TARGETS_START ===', $userPrompt);
        $this->assertStringContainsString('"targetKey": "page:title"', $userPrompt);
        $this->assertStringContainsString('"targetType": "element_html"', $userPrompt);
        $this->assertStringContainsString('"contentFormat": "html"', $userPrompt);
        $this->assertStringContainsString('"sourceLocaleContent": "<p>Welcome to our website.</p>"', $userPrompt);
        $this->assertStringContainsString(
            '"currentTargetContent": "<p>Willkommen auf unserer Website.</p>"',
            $userPrompt
        );
        $this->assertStringContainsString('"translationRequired"', $userPrompt);
        $this->assertStringContainsString('"suggestions"', $userPrompt);
    }
}
