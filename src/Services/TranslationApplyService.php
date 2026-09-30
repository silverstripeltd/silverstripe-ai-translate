<?php

namespace SilverstripeLtd\AiTranslate\Services;

use DNADesign\Elemental\Models\BaseElement;
use LogicException;
use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiTranslate\Exceptions\TranslationApplyException;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationApplyResult;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationRewriteTarget;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\XssSanitiser;
use SilverStripe\Forms\HTMLEditor\HTMLEditorConfig;
use SilverStripe\Forms\HTMLEditor\HTMLEditorSanitiser;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Parsers\HTMLValue;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Applies selected translation suggestions to a record's target-locale draft content.
 */
class TranslationApplyService
{
    public const LOCALISED_RECORD_REQUIRED_MESSAGE = 'AI translate is only available after this page has been'
        . ' localised for the active locale.';
    public const NON_DEFAULT_LOCALE_REQUIRED_MESSAGE = 'Translations are only available for non-default locales';
    public const DEFAULT_LOCALE_MISSING_MESSAGE = 'Default locale is missing';
    public const RECORD_NOT_FOUND_MESSAGE = 'Record not found';
    public const ELEMENT_EDIT_DENIED_MESSAGE = 'A selected block cannot be edited';

    private ContentExtractService $contentExtractService;
    private LoggerInterface $logger;

    /**
     * Builds the apply service with injectable dependencies.
     */
    public function __construct(?ContentExtractService $contentExtractService = null, ?LoggerInterface $logger = null)
    {
        $this->contentExtractService = $contentExtractService ?: Injector::inst()->get(ContentExtractService::class);
        $this->logger = $logger ?: Injector::inst()->get(LoggerInterface::class);
    }

    /**
     * Applies the selected suggestions inside the target locale and restores the default-locale draft afterwards.
     *
     * Each suggestion entry is the payload shape produced by the CMS modal: `apply` (bool or bool-like string),
     * `targetKey`, `suggestedContent`, and optional `targetType`, `targetId` and `fieldName` metadata that must
     * still match the server-known target. Only entries with `apply` set to true are applied.
     *
     * @param array<int|string, array<string, mixed>|mixed> $suggestions
     */
    public function applyToDraft(DataObject $record, Locale $target, array $suggestions): TranslationApplyResult
    {
        $this->ensureRecordCanBeTranslatedInLocale($record, $target);
        $sourceTargetsByKey = $this->getSourceDraftTargetsByKey($record);
        $localeCode = (string) $target->Locale;
        return FluentState::singleton()->withState(
            function (FluentState $state) use (
                $record,
                $suggestions,
                $target,
                $localeCode,
                $sourceTargetsByKey
            ): TranslationApplyResult {
                $state->setLocale($localeCode);
                $draftRecord = $this->prepareDraftRecordInCurrentLocale($record, $localeCode);
                $result = $this->applySuggestionsToDraft($draftRecord, $suggestions, $target);
                $this->restoreSourceDraftTargets($record, $result->appliedTargetKeys, $sourceTargetsByKey);
                return $result;
            }
        );
    }

    /**
     * Rejects default-locale targets and records that have not been localised in the target locale.
     */
    private function ensureRecordCanBeTranslatedInLocale(DataObject $record, Locale $target): void
    {
        if ($this->isDefaultLocale($target)) {
            throw new TranslationApplyException(
                TranslationApplyException::REASON_DEFAULT_LOCALE,
                TranslationApplyService::NON_DEFAULT_LOCALE_REQUIRED_MESSAGE
            );
        }
        $localeCode = (string) $target->Locale;
        if ($record->hasMethod('canAiTranslateInLocale') && $record->canAiTranslateInLocale($localeCode)) {
            return;
        }
        throw new TranslationApplyException(
            TranslationApplyException::REASON_NOT_LOCALISED,
            TranslationApplyService::LOCALISED_RECORD_REQUIRED_MESSAGE
        );
    }

    /**
     * Reports whether one locale is Fluent's default locale.
     */
    private function isDefaultLocale(Locale $locale): bool
    {
        $defaultLocale = Locale::getDefault();
        return $defaultLocale && $defaultLocale->Locale === $locale->Locale;
    }

    /**
     * Returns the default locale or fails when Fluent has none configured.
     */
    private function requireDefaultLocale(): Locale
    {
        $defaultLocale = Locale::getDefault();
        if (!$defaultLocale) {
            throw new TranslationApplyException(
                TranslationApplyException::REASON_DEFAULT_LOCALE_MISSING,
                TranslationApplyService::DEFAULT_LOCALE_MISSING_MESSAGE
            );
        }
        return $defaultLocale;
    }

    /**
     * Applies selected suggestions to page fields and owned Elemental blocks.
     */
    private function applySuggestionsToDraft(
        DataObject $record,
        array $suggestions,
        Locale $targetLocale
    ): TranslationApplyResult {
        $rewriteTargetsByKey = $this->getRewriteTargetsByKey($record, $targetLocale);
        $pageElementalAreaIds = $this->getElementalAreaIds($record);
        $pageElementIds = $this->getElementalElementIds($record);
        $resolvedSuggestions = [];
        $seenTargetKeys = [];
        $skipped = [];
        $pageRequiresWrite = false;
        $appliedTargetKeys = [];
        foreach ($suggestions as $index => $suggestion) {
            if (!is_array($suggestion)) {
                $this->recordApplySkip($skipped, $record, 'invalid-payload', $index);
                continue;
            }
            if (!$this->shouldApplySuggestion($suggestion)) {
                continue;
            }
            $resolvedSuggestion = $this->resolveApplicableSuggestion(
                $record,
                $suggestion,
                $rewriteTargetsByKey,
                $pageElementalAreaIds,
                $pageElementIds,
                $index,
                $seenTargetKeys,
                $skipped
            );
            if ($resolvedSuggestion === []) {
                continue;
            }
            $resolvedSuggestions[] = [
                'index' => $index,
                'suggestedContent' => $resolvedSuggestion['suggestedContent'],
                'target' => $resolvedSuggestion['target'],
            ];
        }
        $this->assertEditableElementTargets($record, $resolvedSuggestions);
        foreach ($resolvedSuggestions as $resolvedSuggestion) {
            if (!$this->applyResolvedSuggestion(
                $record,
                $resolvedSuggestion['target'],
                $resolvedSuggestion['suggestedContent'],
                $pageElementalAreaIds,
                $resolvedSuggestion['index'],
                $pageRequiresWrite,
                $skipped
            )) {
                continue;
            }
            $appliedTargetKeys[] = $resolvedSuggestion['target']->targetKey;
        }
        if ($pageRequiresWrite) {
            $this->writeDraftRecord($record);
        }
        return new TranslationApplyResult(count($appliedTargetKeys), count($skipped), $appliedTargetKeys, $skipped);
    }

    /**
     * Fails the whole apply when any selected block target cannot be edited.
     */
    private function assertEditableElementTargets(DataObject $record, array $resolvedSuggestions): void
    {
        $checkedElementIds = [];
        foreach ($resolvedSuggestions as $resolvedSuggestion) {
            /** @var TranslationRewriteTarget $target */
            $target = $resolvedSuggestion['target'];
            if (!TranslationRewriteTarget::isElementTargetType($target->targetType) || !$target->targetId) {
                continue;
            }
            if (isset($checkedElementIds[$target->targetId])) {
                continue;
            }
            $checkedElementIds[$target->targetId] = true;
            $element = BaseElement::get()->setUseCache(false)->byID($target->targetId);
            if ($element && !$element->canEdit()) {
                $this->logger->warning('AI Translate apply denied by block permissions', [
                    'recordClass' => $record->ClassName,
                    'recordId' => $record->ID,
                    'targetId' => $target->targetId,
                    'targetKey' => $target->targetKey,
                ]);
                throw new TranslationApplyException(
                    TranslationApplyException::REASON_PERMISSION_DENIED,
                    TranslationApplyService::ELEMENT_EDIT_DENIED_MESSAGE
                );
            }
        }
    }

    /**
     * Reports whether the payload entry was explicitly selected for apply.
     */
    private function shouldApplySuggestion(array $suggestion): bool
    {
        if (!array_key_exists('apply', $suggestion)) {
            return false;
        }
        return filter_var($suggestion['apply'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Indexes the current target-locale rewrite targets by target key.
     *
     * @return array<string, TranslationRewriteTarget>
     */
    private function getRewriteTargetsByKey(DataObject $record, Locale $targetLocale): array
    {
        $targetsByKey = [];
        foreach ($this->contentExtractService->extractRewriteTargetsForLocale($record, $targetLocale) as $target) {
            $targetsByKey[$target->targetKey] = $target;
        }
        return $targetsByKey;
    }

    /**
     * Validates and resolves one selected suggestion against current draft targets.
     */
    private function resolveApplicableSuggestion(
        DataObject $record,
        array $suggestion,
        array $rewriteTargetsByKey,
        array $pageElementalAreaIds,
        array $pageElementIds,
        int|string $index,
        array &$seenTargetKeys,
        array &$skipped
    ): array {
        $targetKey = trim((string) ($suggestion['targetKey'] ?? ''));
        if ($targetKey === '') {
            $this->recordApplySkip($skipped, $record, 'missing-target-key', $index);
            return [];
        }
        if (isset($seenTargetKeys[$targetKey])) {
            $this->recordApplySkip($skipped, $record, 'duplicate-target', $index, ['targetKey' => $targetKey]);
            return [];
        }
        $suggestedContent = $suggestion['suggestedContent'] ?? null;
        if (!is_string($suggestedContent)) {
            $this->recordApplySkip($skipped, $record, 'missing-suggested-content', $index, ['targetKey' => $targetKey]);
            return [];
        }
        $target = $rewriteTargetsByKey[$targetKey] ?? null;
        if (!$target) {
            $this->recordApplySkip(
                $skipped,
                $record,
                $this->resolveMissingTargetReason($suggestion, $pageElementalAreaIds, $pageElementIds),
                $index,
                ['targetKey' => $targetKey]
            );
            return [];
        }
        if (!$this->suggestionMatchesTarget($suggestion, $target)) {
            $this->recordApplySkip($skipped, $record, 'target-metadata-mismatch', $index, ['targetKey' => $targetKey]);
            return [];
        }
        $seenTargetKeys[$targetKey] = true;
        return [
            'target' => $target,
            'suggestedContent' => $suggestedContent,
        ];
    }

    /**
     * Applies one validated suggestion to a page field or owned Elemental block.
     */
    private function applyResolvedSuggestion(
        DataObject $record,
        TranslationRewriteTarget $target,
        string $suggestedContent,
        array $pageElementalAreaIds,
        int|string $index,
        bool &$pageRequiresWrite,
        array &$skipped
    ): bool {
        if (TranslationRewriteTarget::isElementTargetType($target->targetType)) {
            return $this->applyElementSuggestion(
                $record,
                $target,
                $suggestedContent,
                $pageElementalAreaIds,
                $index,
                $skipped
            );
        }
        return $this->applyPageSuggestion($record, $target, $suggestedContent, $index, $pageRequiresWrite, $skipped);
    }

    /**
     * Verifies that the payload metadata still matches the current server-known target.
     */
    private function suggestionMatchesTarget(array $suggestion, TranslationRewriteTarget $target): bool
    {
        $payloadTargetType = $suggestion['targetType'] ?? null;
        if (is_string($payloadTargetType)
            && trim($payloadTargetType) !== ''
            && trim($payloadTargetType) !== $target->targetType) {
            return false;
        }
        $payloadFieldName = $suggestion['fieldName'] ?? null;
        if (is_string($payloadFieldName)
            && trim($payloadFieldName) !== ''
            && trim($payloadFieldName) !== $target->fieldName) {
            return false;
        }
        if (!array_key_exists('targetId', $suggestion)) {
            return true;
        }
        $payloadTargetId = $suggestion['targetId'];
        if ($payloadTargetId === null || $payloadTargetId === '') {
            return $target->targetId === null;
        }
        if (!is_int($payloadTargetId) && !(is_string($payloadTargetId) && ctype_digit($payloadTargetId))) {
            return false;
        }
        return (int) $payloadTargetId === $target->targetId;
    }

    /**
     * Explains why a missing target should be treated as deleted, foreign, or mismatched.
     */
    private function resolveMissingTargetReason(
        array $suggestion,
        array $pageElementalAreaIds,
        array $pageElementIds
    ): string {
        $payloadTargetType = trim((string) ($suggestion['targetType'] ?? ''));
        $payloadTargetId = $suggestion['targetId'] ?? null;
        if (!TranslationRewriteTarget::isElementTargetType($payloadTargetType)) {
            return 'mismatched-target';
        }
        if (!is_int($payloadTargetId) && !(is_string($payloadTargetId) && ctype_digit($payloadTargetId))) {
            return 'mismatched-target';
        }
        $element = BaseElement::get()->byID((int) $payloadTargetId);
        if (!$element) {
            return 'deleted-target';
        }
        if (!in_array((int) $element->ParentID, $pageElementalAreaIds, true)) {
            return 'foreign-target';
        }
        if (!in_array((int) $payloadTargetId, $pageElementIds, true)) {
            return 'deleted-target';
        }
        return 'mismatched-target';
    }

    /**
     * Stages one page-level suggestion and defers the page write until the loop finishes.
     */
    private function applyPageSuggestion(
        DataObject $record,
        TranslationRewriteTarget $target,
        string $suggestedContent,
        int|string $index,
        bool &$pageRequiresWrite,
        array &$skipped
    ): bool {
        if (!$record->hasField($target->fieldName)) {
            $this->recordApplySkip(
                $skipped,
                $record,
                'missing-target-field',
                $index,
                ['targetKey' => $target->targetKey, 'fieldName' => $target->fieldName]
            );
            return false;
        }
        $record->setField(
            $target->fieldName,
            $this->sanitiseSuggestedContent($record, $target->fieldName, $suggestedContent)
        );
        $pageRequiresWrite = true;
        return true;
    }

    /**
     * Writes one Elemental suggestion after ownership and field checks pass.
     */
    private function applyElementSuggestion(
        DataObject $record,
        TranslationRewriteTarget $target,
        string $suggestedContent,
        array $pageElementalAreaIds,
        int|string $index,
        array &$skipped
    ): bool {
        if (!$target->targetId) {
            $this->recordApplySkip($skipped, $record, 'missing-target-id', $index, ['targetKey' => $target->targetKey]);
            return false;
        }
        $element = BaseElement::get()->setUseCache(false)->byID($target->targetId);
        if (!$element) {
            $this->recordApplySkip($skipped, $record, 'deleted-target', $index, ['targetKey' => $target->targetKey]);
            return false;
        }
        if (!in_array((int) $element->ParentID, $pageElementalAreaIds, true)) {
            $this->recordApplySkip($skipped, $record, 'foreign-target', $index, ['targetKey' => $target->targetKey]);
            return false;
        }
        if (!$element->hasField($target->fieldName)) {
            $this->recordApplySkip(
                $skipped,
                $record,
                'missing-target-field',
                $index,
                ['targetKey' => $target->targetKey, 'fieldName' => $target->fieldName]
            );
            return false;
        }
        $element->setField(
            $target->fieldName,
            $this->sanitiseSuggestedContent($element, $target->fieldName, $suggestedContent)
        );
        $this->writeDraftRecord($element);
        return true;
    }

    /**
     * Applies CMS-equivalent sanitisation before suggestion content is written.
     */
    private function sanitiseSuggestedContent(DataObject $record, string $fieldName, string $suggestedContent): string
    {
        $dbField = $record->dbObject($fieldName);
        if ($dbField instanceof DBHTMLText || $dbField instanceof DBHTMLVarchar) {
            $htmlValue = new HTMLValue($suggestedContent);
            HTMLEditorSanitiser::create(HTMLEditorConfig::get_active())->sanitise($htmlValue);
            XssSanitiser::create()->sanitiseHtmlValue($htmlValue);
            return $htmlValue->getContent();
        }
        return strip_tags($suggestedContent);
    }

    /**
     * Collects the Elemental area IDs owned by the current page record.
     *
     * @return array<int, int>
     */
    private function getElementalAreaIds(DataObject $record): array
    {
        if (!$record->hasMethod('getElementalRelations')) {
            return [];
        }
        $relations = $record->getElementalRelations();
        if (!is_array($relations)) {
            return [];
        }
        $areaIds = [];
        foreach ($relations as $relation) {
            if (!is_string($relation) || !$record->hasMethod($relation)) {
                continue;
            }
            $area = $record->$relation();
            if ($area && $area->exists()) {
                $areaIds[] = (int) $area->ID;
            }
        }
        return array_values(array_unique($areaIds));
    }

    /**
     * Collects the Elemental block IDs currently owned by the current page record.
     *
     * @return array<int, int>
     */
    private function getElementalElementIds(DataObject $record): array
    {
        if (!$record->hasMethod('getElementalRelations')) {
            return [];
        }
        $relations = $record->getElementalRelations();
        if (!is_array($relations)) {
            return [];
        }
        $elementIds = [];
        foreach ($relations as $relation) {
            if (!is_string($relation) || !$record->hasMethod($relation)) {
                continue;
            }
            $area = $record->$relation();
            if (!$area || !$area->exists()) {
                continue;
            }
            foreach ($area->Elements() as $element) {
                if ($element instanceof BaseElement && $element->exists()) {
                    $elementIds[] = (int) $element->ID;
                }
            }
        }
        return array_values(array_unique($elementIds));
    }

    /**
     * Records and logs the reason one apply payload entry was skipped.
     */
    private function recordApplySkip(
        array &$skipped,
        DataObject $record,
        string $reason,
        int|string $index,
        array $context = []
    ): void {
        $skipped[] = array_merge(['index' => $index, 'reason' => $reason], $context);
        $this->logger->warning('AI Translate apply skipped suggestion', array_merge([
            'reason' => $reason,
            'recordClass' => $record->ClassName,
            'recordId' => $record->ID,
            'suggestionIndex' => $index,
        ], $context));
    }

    /**
     * Captures the current default-locale draft values keyed by stable target key.
     *
     * @return array<string, TranslationRewriteTarget>
     */
    private function getSourceDraftTargetsByKey(DataObject $record): array
    {
        $defaultLocale = $this->requireDefaultLocale();
        $sourceTargetsByKey = [];
        FluentState::singleton()->withState(
            function (FluentState $state) use ($record, $defaultLocale, &$sourceTargetsByKey): void {
                $state->setLocale((string) $defaultLocale->Locale);
                $sourceRecord = $this->loadDraftRecordInCurrentLocale($record);
                $sourceTargets = $this->contentExtractService
                    ->extractRewriteTargetsForLocale($sourceRecord, $defaultLocale);
                foreach ($sourceTargets as $target) {
                    $sourceTargetsByKey[$target->targetKey] = $target;
                }
            }
        );
        return $sourceTargetsByKey;
    }

    /**
     * Ensures the current locale has its own Draft record before suggestion fields are mutated.
     */
    private function prepareDraftRecordInCurrentLocale(DataObject $record, string $localeCode): DataObject
    {
        $draftRecord = $this->loadDraftRecordInCurrentLocale($record);
        if ($this->needsDraftLocalisation($draftRecord, $localeCode)) {
            $this->copyDefaultDraftToLocale($record, $localeCode);
            $draftRecord = $this->loadDraftRecordInCurrentLocale($record);
        }
        return $draftRecord;
    }

    /**
     * Reloads the current record from the active locale's Draft stage.
     */
    private function loadDraftRecordInCurrentLocale(DataObject $record): DataObject
    {
        if (!$record->hasExtension(Versioned::class)) {
            $draftRecord = DataObject::get($record->ClassName)->setUseCache(false)->byID($record->ID);
        } else {
            $draftRecord = Versioned::withVersionedMode(function () use ($record): ?DataObject {
                Versioned::set_stage(Versioned::DRAFT);
                return DataObject::get($record->ClassName)->setUseCache(false)->byID($record->ID);
            });
        }
        if (!$draftRecord) {
            throw new TranslationApplyException(
                TranslationApplyException::REASON_RECORD_NOT_FOUND,
                TranslationApplyService::RECORD_NOT_FOUND_MESSAGE
            );
        }
        return $draftRecord;
    }

    /**
     * Reports whether Draft localisation must be created before fields can be safely changed.
     */
    private function needsDraftLocalisation(DataObject $record, string $localeCode): bool
    {
        if ($record->hasExtension(Versioned::class)) {
            return !$record->isDraftedInLocale($localeCode);
        }
        if ($record->hasMethod('existsInLocale')) {
            return !$record->existsInLocale($localeCode);
        }
        return false;
    }

    /**
     * Creates the first target-locale draft by copying the default-locale draft into it.
     */
    private function copyDefaultDraftToLocale(DataObject $record, string $targetLocaleCode): void
    {
        $sourceLocaleCode = (string) $this->requireDefaultLocale()->Locale;
        FluentState::singleton()->withState(
            function (FluentState $state) use ($record, $sourceLocaleCode, $targetLocaleCode): void {
                $state->setLocale($sourceLocaleCode);
                $sourceRecord = $this->loadDraftRecordInCurrentLocale($record);
                if ($sourceRecord->hasMethod('copyToLocale')) {
                    $sourceRecord->copyToLocale($targetLocaleCode);
                    return;
                }
                $this->writeDraftRecord($sourceRecord);
            }
        );
    }

    /**
     * Restores the shared default-locale draft values for the page targets touched by apply.
     *
     * @param array<int, string> $appliedTargetKeys
     * @param array<string, TranslationRewriteTarget> $sourceTargetsByKey
     */
    private function restoreSourceDraftTargets(
        DataObject $record,
        array $appliedTargetKeys,
        array $sourceTargetsByKey
    ): void {
        if ($appliedTargetKeys === []) {
            return;
        }
        $defaultLocale = $this->requireDefaultLocale();
        FluentState::singleton()->withState(
            function (FluentState $state) use ($record, $defaultLocale, $appliedTargetKeys, $sourceTargetsByKey): void {
                $state->setLocale((string) $defaultLocale->Locale);
                $sourceRecord = $this->loadDraftRecordInCurrentLocale($record);
                $sourceElementsById = [];
                $pageRequiresWrite = false;
                foreach ($appliedTargetKeys as $targetKey) {
                    $sourceTarget = $sourceTargetsByKey[$targetKey] ?? null;
                    if (!$sourceTarget) {
                        throw new LogicException(sprintf('Missing source draft target for "%s"', $targetKey));
                    }
                    if (TranslationRewriteTarget::isElementTargetType($sourceTarget->targetType)) {
                        $this->stageSourceElementRestore($sourceTarget, $sourceElementsById);
                        continue;
                    }
                    if (!$sourceRecord->hasField($sourceTarget->fieldName)) {
                        throw new LogicException(sprintf('Missing source draft page field for "%s"', $targetKey));
                    }
                    $sourceRecord->setField($sourceTarget->fieldName, $sourceTarget->content);
                    $pageRequiresWrite = true;
                }
                if ($pageRequiresWrite) {
                    $this->writeDraftRecord($sourceRecord);
                }
                foreach ($sourceElementsById as $sourceElement) {
                    $this->writeDraftRecord($sourceElement);
                }
            }
        );
    }

    /**
     * Stages one source-locale Elemental target so it can be restored after apply.
     *
     * @param array<int, BaseElement> $sourceElementsById
     */
    private function stageSourceElementRestore(
        TranslationRewriteTarget $sourceTarget,
        array &$sourceElementsById
    ): void {
        if (!$sourceTarget->targetId) {
            throw new LogicException(sprintf('Missing source draft element ID for "%s"', $sourceTarget->targetKey));
        }
        $sourceElement = $sourceElementsById[$sourceTarget->targetId] ?? BaseElement::get()
            ->setUseCache(false)
            ->byID($sourceTarget->targetId);
        if (!$sourceElement) {
            throw new LogicException(sprintf('Missing source draft element for "%s"', $sourceTarget->targetKey));
        }
        if (!$sourceElement->hasField($sourceTarget->fieldName)) {
            throw new LogicException(sprintf('Missing source draft element field for "%s"', $sourceTarget->targetKey));
        }
        $sourceElement->setField($sourceTarget->fieldName, $sourceTarget->content);
        $sourceElementsById[$sourceTarget->targetId] = $sourceElement;
    }

    /**
     * Persists one record back to the current locale's Draft stage.
     */
    private function writeDraftRecord(DataObject $record): void
    {
        if (!$record->hasExtension(Versioned::class)) {
            $record->write();
            return;
        }
        $record->writeToStage(Versioned::DRAFT);
    }
}
