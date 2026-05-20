<?php

namespace SilverstripeLtd\AiTranslate\ValueObjects;

/**
 * Stores the extracted source payload and locale-specific targets.
 */
class TranslationExtractedContent
{
    /**
     * Creates one dual-locale extraction result.
     *
     * @param array<int, TranslationRewriteTarget> $sourceRewriteTargets
     * @param array<int, TranslationRewriteTarget> $targetRewriteTargets
     */
    public function __construct(
        public readonly string $sourceContent,
        public readonly array $sourceRewriteTargets,
        public readonly array $targetRewriteTargets
    ) {
    }

    /**
     * Reports whether the extracted source payload is empty.
     */
    public function isEmpty(): bool
    {
        return trim($this->sourceContent) === '';
    }
}
