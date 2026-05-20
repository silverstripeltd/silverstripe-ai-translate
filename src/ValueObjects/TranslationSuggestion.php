<?php

namespace SilverstripeLtd\AiTranslate\ValueObjects;

/**
 * Stores one validated translation suggestion for a server-known target.
 */
class TranslationSuggestion
{
    /**
     * Creates one translation suggestion.
     */
    public function __construct(
        public readonly string $targetKey,
        public readonly string $targetType,
        public readonly string $suggestedContent,
        public readonly ?int $targetId = null,
        public readonly string $fieldName = '',
        public readonly string $fieldLabel = '',
        public readonly string $targetTitle = '',
        public readonly string $sourceLocaleContent = '',
        public readonly string $currentTargetContent = '',
        public readonly string $contentFormat = 'text'
    ) {
    }

    /**
     * Resolves locale-specific metadata onto a validated provider suggestion.
     */
    public function withResolvedTargets(
        TranslationRewriteTarget $sourceTarget,
        TranslationRewriteTarget $targetTarget
    ): TranslationSuggestion {
        return new TranslationSuggestion(
            $this->targetKey,
            $this->targetType,
            $this->suggestedContent,
            $targetTarget->targetId,
            $targetTarget->fieldName,
            $targetTarget->fieldLabel,
            $targetTarget->targetTitle,
            $sourceTarget->content,
            $targetTarget->content,
            $targetTarget->contentFormat
        );
    }

    /**
     * Serialises the suggestion into the controller response shape.
     */
    public function toArray(): array
    {
        return [
            'targetKey' => $this->targetKey,
            'targetType' => $this->targetType,
            'targetId' => $this->targetId,
            'fieldName' => $this->fieldName,
            'fieldLabel' => $this->fieldLabel,
            'targetTitle' => $this->targetTitle,
            'sourceLocaleContent' => $this->sourceLocaleContent,
            'currentTargetContent' => $this->currentTargetContent,
            'suggestedContent' => $this->suggestedContent,
            'contentFormat' => $this->contentFormat,
        ];
    }
}
