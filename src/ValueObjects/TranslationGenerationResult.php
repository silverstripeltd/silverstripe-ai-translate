<?php

namespace SilverstripeLtd\AiTranslate\ValueObjects;

/**
 * Stores one generation result for the review modal.
 */
class TranslationGenerationResult
{
    /**
     * Creates one generation result.
     *
     * @param array<int, TranslationSuggestion> $suggestions
     */
    public function __construct(
        public readonly bool $alreadyMatchesLocale,
        public readonly array $suggestions
    ) {
    }
}
