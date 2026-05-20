<?php

namespace SilverstripeLtd\AiTranslate\Services;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\BaseElement;
use DNADesign\Elemental\Models\ElementContent;
use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationExtractedContent;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationRewriteTarget;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Exception\MissingTemplateException;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Extracts dual-locale translation content and rewrite targets.
 */
class ContentExtractService
{
    use Extensible;

    private LoggerInterface $logger;

    /**
     * Builds the extractor with an optional logger dependency.
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?: Injector::inst()->get(LoggerInterface::class);
    }

    /**
     * Extracts the source payload plus source and target rewrite targets.
     */
    public function extract(
        DataObject $record,
        Locale $sourceLocale,
        Locale $targetLocale
    ): TranslationExtractedContent {
        $sourceRecord = $this->loadRecordForLocale($record, $sourceLocale);
        $targetRecord = $this->loadRecordForLocale($record, $targetLocale);
        if (!$sourceRecord || !$targetRecord) {
            return new TranslationExtractedContent('', [], []);
        }
        $sourceContent = $this->buildSourceContent($sourceRecord, $sourceLocale);
        $sourceRewriteTargets = $this->buildRewriteTargets($sourceRecord, $sourceLocale, false);
        $targetRewriteTargets = $this->alignTargetRewriteTargets(
            $sourceRewriteTargets,
            $this->buildRewriteTargets($targetRecord, $targetLocale, true)
        );
        return new TranslationExtractedContent($sourceContent, $sourceRewriteTargets, $targetRewriteTargets);
    }

    /**
     * Re-extracts the current target-locale draft rewrite targets for apply validation.
     *
     * @return array<int, TranslationRewriteTarget>
     */
    public function extractRewriteTargetsForLocale(DataObject $record, Locale $locale): array
    {
        $resolvedRecord = $this->loadRecordForLocale($record, $locale);
        if (!$resolvedRecord) {
            return [];
        }
        return $this->buildRewriteTargets($resolvedRecord, $locale, true);
    }

    /**
     * Builds the flat source payload used for the empty-content gate.
     */
    private function buildSourceContent(DataObject $record, Locale $locale): string
    {
        $parts = [];
        $title = trim((string) ($record->hasField('Title') ? $record->Title : ''));
        if ($title !== '') {
            $parts[] = $title;
        }
        $content = $this->buildPrimaryBodyContent($record);
        if ($content !== '') {
            $parts[] = $content;
        }
        $extractedContent = trim(implode("\n\n", $parts));
        $this->extend('updateExtractedContent', $extractedContent, $record, $locale);
        return trim($extractedContent);
    }

    /**
     * Builds the main text body from Elemental search content or page content.
     */
    private function buildPrimaryBodyContent(DataObject $record): string
    {
        $content = '';
        if ($record->hasMethod('getElementsForSearch')) {
            $content = $this->buildElementalSearchContent($record);
        }
        if ($content === '' && $record->hasField('Content')) {
            $content = $this->normaliseTextContent(Convert::html2raw((string) $record->Content));
        }
        return $content;
    }

    /**
     * Extracts Elemental search text with a template-free fallback.
     */
    private function buildElementalSearchContent(DataObject $record): string
    {
        try {
            return $this->normaliseTextContent((string) $record->getElementsForSearch());
        } catch (MissingTemplateException $exception) {
            $this->logger->warning('AI Translate extraction fell back to Elemental CMS search content', [
                'recordClass' => $record->ClassName,
                'recordId' => $record->exists() ? (int) $record->ID : null,
                'exceptionClass' => $exception::class,
            ]);
        }
        if (!$record->hasMethod('getContentFromElementsForCmsSearch')) {
            return '';
        }
        $content = str_replace(['|%|', '|#|'], ' ', (string) $record->getContentFromElementsForCmsSearch());
        return $this->normaliseTextContent($content);
    }

    /**
     * Builds the structured rewrite targets for one locale.
     *
     * @return array<int, TranslationRewriteTarget>
     */
    private function buildRewriteTargets(DataObject $record, Locale $locale, bool $includeEmptyTargets): array
    {
        $targets = [];
        $recordId = $record->exists() ? (int) $record->ID : null;
        $title = trim((string) ($record->hasField('Title') ? $record->Title : ''));
        if ($record->hasField('Title') && ($title !== '' || $includeEmptyTargets)) {
            $targets[] = $this->createRewriteTarget(
                $record,
                'page:title',
                TranslationRewriteTarget::TYPE_PAGE_TITLE,
                'Title',
                $recordId,
                $title,
                'text'
            );
        }
        $elementTargets = $this->buildElementRewriteTargets($record, $locale, $includeEmptyTargets);
        if ($elementTargets !== []) {
            $targets = array_merge($targets, $elementTargets);
            $this->extend('updateExtractedRewriteTargets', $targets, $record, $locale);
            return $targets;
        }
        if ($record->hasField('Content')) {
            $rawContent = trim((string) $record->Content);
            if ($rawContent !== '' || $includeEmptyTargets) {
                $targets[] = $this->createRewriteTarget(
                    $record,
                    'page:content',
                    TranslationRewriteTarget::TYPE_PAGE_CONTENT,
                    'Content',
                    $recordId,
                    $rawContent,
                    'html'
                );
            }
        }
        $this->extend('updateExtractedRewriteTargets', $targets, $record, $locale);
        return $targets;
    }

    /**
     * Builds rewrite targets for supported Elemental block fields.
     *
     * @return array<int, TranslationRewriteTarget>
     */
    private function buildElementRewriteTargets(DataObject $record, Locale $locale, bool $includeEmptyTargets): array
    {
        if (!$record->hasMethod('getElementalRelations')) {
            return [];
        }
        $relations = $record->getElementalRelations();
        if (!is_array($relations)) {
            return [];
        }
        $targets = [];
        foreach ($relations as $relation) {
            if (!is_string($relation) || !$record->hasMethod($relation)) {
                continue;
            }
            $area = $record->$relation();
            if (!$area || !$area->exists()) {
                continue;
            }
            foreach ($area->Elements() as $element) {
                if (!$element instanceof BaseElement) {
                    continue;
                }
                if (!$element->canView()) {
                    continue;
                }
                $targets = array_merge(
                    $targets,
                    $this->buildElementFieldTargets($element, $locale, $includeEmptyTargets)
                );
            }
        }
        return $targets;
    }

    /**
     * Builds rewrite targets for one Elemental block.
     *
     * @return array<int, TranslationRewriteTarget>
     */
    private function buildElementFieldTargets(BaseElement $element, Locale $locale, bool $includeEmptyTargets): array
    {
        $targets = [];
        foreach ($this->getSupportedElementFieldTypes($element) as $fieldName => $targetType) {
            $rawContent = trim((string) $element->getField($fieldName));
            $content = $targetType === TranslationRewriteTarget::TYPE_ELEMENT_HTML
                ? $rawContent
                : $this->normaliseTextContent($rawContent);
            if ($content === '' && !$includeEmptyTargets) {
                continue;
            }
            $targets[] = $this->createRewriteTarget(
                $element,
                $this->buildElementTargetKey($element, $fieldName, $targetType),
                $targetType,
                $fieldName,
                (int) $element->ID,
                $content,
                $targetType === TranslationRewriteTarget::TYPE_ELEMENT_HTML ? 'html' : 'text'
            );
        }
        return $targets;
    }

    /**
     * Creates one structured target with labels resolved from the record.
     */
    private function createRewriteTarget(
        DataObject $record,
        string $targetKey,
        string $targetType,
        string $fieldName,
        ?int $targetId,
        string $content,
        string $contentFormat
    ): TranslationRewriteTarget {
        return new TranslationRewriteTarget(
            $targetKey,
            $targetType,
            $fieldName,
            $targetId,
            $this->resolveFieldLabel($record, $fieldName),
            $this->resolveTargetTitle($record),
            $content,
            $contentFormat
        );
    }

    /**
     * Aligns current target-locale targets to the source target order and keys.
     *
     * @param array<int, TranslationRewriteTarget> $sourceRewriteTargets
     * @param array<int, TranslationRewriteTarget> $targetRewriteTargets
     * @return array<int, TranslationRewriteTarget>
     */
    private function alignTargetRewriteTargets(array $sourceRewriteTargets, array $targetRewriteTargets): array
    {
        $targetTargetsByKey = [];
        foreach ($targetRewriteTargets as $targetRewriteTarget) {
            $targetTargetsByKey[$targetRewriteTarget->targetKey] = $targetRewriteTarget;
        }
        $alignedTargets = [];
        foreach ($sourceRewriteTargets as $sourceRewriteTarget) {
            $alignedTargets[] = $targetTargetsByKey[$sourceRewriteTarget->targetKey]
                ?? $sourceRewriteTarget->withContent('');
        }
        return $alignedTargets;
    }

    /**
     * Detects which Elemental database fields should become translation targets.
     *
     * @return array<string, string>
     */
    private function getSupportedElementFieldTypes(BaseElement $element): array
    {
        $supported = [];
        $excludedFields = $this->getExcludedElementFieldNames($element);
        foreach (DataObject::getSchema()->databaseFields($element) as $fieldName => $databaseFieldType) {
            if (in_array($fieldName, $excludedFields, true)) {
                continue;
            }
            $targetType = $this->resolveElementTargetType((string) $databaseFieldType);
            if ($targetType === '') {
                continue;
            }
            $supported[$fieldName] = $targetType;
        }
        return $supported;
    }

    /**
     * Returns Elemental fields that must never become translation targets.
     *
     * @return array<int, string>
     */
    private function getExcludedElementFieldNames(BaseElement $element): array
    {
        return array_values(array_unique([
            ...array_filter(
                array_keys((array) BaseElement::config()->get('db')),
                static fn(string $fieldName): bool => $fieldName !== 'Title'
            ),
            ...array_keys((array) $element->config()->get('fixed_fields')),
            ...(array) $element->config()->get('fields_excluded_from_cms_search'),
        ]));
    }

    /**
     * Maps an ORM field type onto one translation target type.
     */
    private function resolveElementTargetType(string $databaseFieldType): string
    {
        $lowercaseType = strtolower(strtok($databaseFieldType, '(') ?: $databaseFieldType);
        if (str_contains($lowercaseType, 'html')) {
            return TranslationRewriteTarget::TYPE_ELEMENT_HTML;
        }
        if (str_contains($lowercaseType, 'varchar') || str_contains($lowercaseType, 'text')) {
            return TranslationRewriteTarget::TYPE_ELEMENT_TEXT;
        }
        return '';
    }

    /**
     * Builds the stable target key for one Elemental block field.
     */
    private function buildElementTargetKey(BaseElement $element, string $fieldName, string $targetType): string
    {
        if ($targetType === TranslationRewriteTarget::TYPE_ELEMENT_HTML
            && $element instanceof ElementContent
            && $fieldName === 'HTML') {
            return sprintf('element:%d:html', $element->ID);
        }
        return sprintf('element:%d:field:%s', $element->ID, strtolower($fieldName));
    }

    /**
     * Resolves the UI label for one target field.
     */
    private function resolveFieldLabel(DataObject $record, string $fieldName): string
    {
        $label = trim((string) $record->fieldLabel($fieldName));
        return $label !== '' ? $label : $fieldName;
    }

    /**
     * Resolves the display title for one target.
     */
    private function resolveTargetTitle(DataObject $record): string
    {
        if (!$record instanceof BaseElement) {
            return '';
        }
        $title = trim((string) ($record->hasField('Title') ? $record->getField('Title') : ''));
        if ($title !== '') {
            return $title;
        }
        if ($record->hasMethod('getType')) {
            $type = trim((string) $record->getType());
            if ($type !== '' && strtolower($type) !== 'block') {
                return $type;
            }
        }
        return '';
    }

    /**
     * Normalises plain-text content before prompting or diffing.
     */
    private function normaliseTextContent(string $content): string
    {
        $normalisedContent = preg_replace('/\s+/u', ' ', trim($content));
        return $normalisedContent !== null ? trim($normalisedContent) : trim($content);
    }

    /**
     * Reloads the record in Draft stage for one Fluent locale.
     */
    private function loadRecordForLocale(DataObject $record, Locale $locale): ?DataObject
    {
        if (!$record->exists()) {
            return $record;
        }
        $localeCode = (string) $locale->Locale;
        return FluentState::singleton()->withState(
            function (FluentState $state) use ($record, $localeCode): ?DataObject {
                $state->setLocale($localeCode);
                if (!$record->hasExtension(Versioned::class)) {
                    $this->resetElementalCache();
                    return DataObject::get($record->ClassName)->byID($record->ID) ?: $record;
                }
                return Versioned::withVersionedMode(function () use ($record): ?DataObject {
                    Versioned::set_stage(Versioned::DRAFT);
                    $this->resetElementalCache();
                    return DataObject::get($record->ClassName)->byID($record->ID) ?: $record;
                });
            }
        );
    }

    /**
     * Clears Elemental's cached area map before stage and locale reads.
     */
    private function resetElementalCache(): void
    {
        $hasElementalCache = class_exists(ElementalPageExtension::class)
            && property_exists(ElementalPageExtension::class, 'elementalAreas');
        if (!$hasElementalCache) {
            return;
        }
        $property = new \ReflectionProperty(ElementalPageExtension::class, 'elementalAreas');
        if (!$property->isStatic()) {
            return;
        }
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
