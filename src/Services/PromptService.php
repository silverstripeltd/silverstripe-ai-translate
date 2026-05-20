<?php

namespace SilverstripeLtd\AiTranslate\Services;

use SilverstripeLtd\AiTranslate\ValueObjects\TranslationExtractedContent;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationRewriteTarget;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injector;
use TractorCow\Fluent\Model\Locale;

/**
 * Builds translation prompts from prompt templates and rewrite targets.
 */
class PromptService
{
    use Extensible;

    private LocaleLabelService $localeLabelService;

    /**
     * Builds the service with an optional locale-label dependency.
     */
    public function __construct(?LocaleLabelService $localeLabelService = null)
    {
        $this->localeLabelService = $localeLabelService ?: Injector::inst()->get(LocaleLabelService::class);
    }

    /**
     * Builds the system and user prompts for one translation request.
     *
     * @return array{0: string, 1: string}
     */
    public function buildPrompts(
        TranslationExtractedContent $extractedContent,
        Locale $sourceLocale,
        Locale $targetLocale
    ): array {
        $systemPrompt = $this->getSystemPrompt();
        $userPrompt = $this->renderUserPrompt($extractedContent, $sourceLocale, $targetLocale);
        $this->extend('updateTranslationPrompts', $systemPrompt, $userPrompt, $sourceLocale, $targetLocale);
        return [$systemPrompt, $userPrompt];
    }

    /**
     * Loads the system prompt template from disk.
     */
    public function getSystemPrompt(): string
    {
        return trim((string) file_get_contents($this->getPromptsDirectory() . '/system.md'));
    }

    /**
     * Renders the user prompt with locale labels and rewrite-target JSON.
     */
    private function renderUserPrompt(
        TranslationExtractedContent $extractedContent,
        Locale $sourceLocale,
        Locale $targetLocale
    ): string {
        $template = trim((string) file_get_contents($this->getPromptsDirectory() . '/user.md'));
        return trim(str_replace(
            ['{sourceLanguage}', '{targetLanguage}', '{rewriteTargetsJson}'],
            [
                $this->localeLabelService->getLanguageLabel($sourceLocale),
                $this->localeLabelService->getLanguageLabel($targetLocale),
                $this->serialiseRewriteTargets(
                    $extractedContent->sourceRewriteTargets,
                    $extractedContent->targetRewriteTargets
                ),
            ],
            $template
        ));
    }

    /**
     * Serialises rewrite targets into the JSON structure sent to the AI provider.
     *
     * @param array<int, TranslationRewriteTarget> $sourceRewriteTargets
     * @param array<int, TranslationRewriteTarget> $targetRewriteTargets
     */
    private function serialiseRewriteTargets(array $sourceRewriteTargets, array $targetRewriteTargets): string
    {
        $promptPayload = [];
        foreach ($sourceRewriteTargets as $index => $sourceRewriteTarget) {
            $targetRewriteTarget = $targetRewriteTargets[$index] ?? $sourceRewriteTarget->withContent('');
            $promptPayload[] = $sourceRewriteTarget->toPromptPayload($targetRewriteTarget->content);
        }
        return (string) json_encode(
            $promptPayload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Resolves the module prompt template directory.
     */
    private function getPromptsDirectory(): string
    {
        return dirname(__DIR__, 2) . '/prompts';
    }
}
