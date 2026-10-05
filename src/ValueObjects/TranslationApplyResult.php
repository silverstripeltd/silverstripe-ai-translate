<?php

namespace SilverstripeLtd\AiTranslate\ValueObjects;

/**
 * Summarises the outcome of applying translation suggestions to a target-locale draft.
 */
class TranslationApplyResult
{
    /**
     * Creates one apply result.
     *
     * @param array<int, string> $appliedTargetKeys
     * @param array<int, array{index: int|string, reason: string, targetKey?: string}> $skipped
     */
    public function __construct(
        public readonly int $appliedCount,
        public readonly int $skippedCount,
        public readonly array $appliedTargetKeys = [],
        public readonly array $skipped = []
    ) {
    }

    /**
     * Reports whether any draft content changed and the CMS should reload.
     */
    public function isReloadRequired(): bool
    {
        return $this->appliedCount > 0;
    }

    /**
     * Serialises the result into the controller response shape.
     */
    public function toArray(): array
    {
        return [
            'appliedCount' => $this->appliedCount,
            'skippedCount' => $this->skippedCount,
            'reloadRequired' => $this->isReloadRequired(),
        ];
    }
}
