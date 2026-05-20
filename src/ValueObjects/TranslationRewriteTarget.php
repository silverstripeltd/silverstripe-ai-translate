<?php

namespace SilverstripeLtd\AiTranslate\ValueObjects;

/**
 * Stores the server-known metadata for one translatable target.
 */
class TranslationRewriteTarget
{
    public const TYPE_PAGE_TITLE = 'page_title';
    public const TYPE_PAGE_CONTENT = 'page_content';
    public const TYPE_ELEMENT_HTML = 'element_html';
    public const TYPE_ELEMENT_TEXT = 'element_text';

    /**
     * Creates one structured translation target.
     */
    public function __construct(
        public readonly string $targetKey,
        public readonly string $targetType,
        public readonly string $fieldName,
        public readonly ?int $targetId,
        public readonly string $fieldLabel,
        public readonly string $targetTitle,
        public readonly string $content,
        public readonly string $contentFormat
    ) {
    }

    /**
     * Reports whether a target type is supported by the module.
     */
    public static function isValidTargetType(string $targetType): bool
    {
        return in_array($targetType, [
            TranslationRewriteTarget::TYPE_PAGE_TITLE,
            TranslationRewriteTarget::TYPE_PAGE_CONTENT,
            TranslationRewriteTarget::TYPE_ELEMENT_HTML,
            TranslationRewriteTarget::TYPE_ELEMENT_TEXT,
        ], true);
    }

    /**
     * Reports whether a target belongs to an Elemental block.
     */
    public static function isElementTargetType(string $targetType): bool
    {
        return in_array($targetType, [
            TranslationRewriteTarget::TYPE_ELEMENT_HTML,
            TranslationRewriteTarget::TYPE_ELEMENT_TEXT,
        ], true);
    }

    /**
     * Reports whether this target expects HTML content.
     */
    public function isHtmlTarget(): bool
    {
        return $this->contentFormat === 'html';
    }

    /**
     * Returns a copy with different locale-specific content.
     */
    public function withContent(string $content): TranslationRewriteTarget
    {
        return new TranslationRewriteTarget(
            $this->targetKey,
            $this->targetType,
            $this->fieldName,
            $this->targetId,
            $this->fieldLabel,
            $this->targetTitle,
            $content,
            $this->contentFormat
        );
    }

    /**
     * Serialises the prompt payload sent to the AI provider.
     */
    public function toPromptPayload(string $currentTargetContent = ''): array
    {
        return [
            'targetKey' => $this->targetKey,
            'targetType' => $this->targetType,
            'contentFormat' => $this->contentFormat,
            'sourceLocaleContent' => $this->content,
            'currentTargetContent' => $currentTargetContent,
        ];
    }
}
