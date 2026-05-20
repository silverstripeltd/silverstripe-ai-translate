<?php

namespace SilverstripeLtd\AiTranslate\Controllers;

use DOMElement;
use DNADesign\Elemental\Models\BaseElement;
use LogicException;
use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiTranslate\Exceptions\AIProviderException;
use SilverstripeLtd\AiTranslate\Extensions\AiTranslateExtension;
use SilverstripeLtd\AiTranslate\Forms\AiTranslateForm;
use SilverstripeLtd\AiTranslate\Services\AiTranslateRateLimiter;
use SilverstripeLtd\AiTranslate\Services\ContentExtractService;
use SilverstripeLtd\AiTranslate\Services\LocaleLabelService;
use SilverstripeLtd\AiTranslate\Services\TranslationGenerationService;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationRewriteTarget;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationSuggestion;
use SilverStripe\Admin\FormSchemaController;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\XssSanitiser;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\HTMLEditor\HTMLEditorConfig;
use SilverStripe\Forms\HTMLEditor\HTMLEditorSanitiser;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Parsers\HtmlDiff;
use SilverStripe\View\Parsers\HTMLValue;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Serves schema, translation, and apply responses for the CMS translation modal.
 */
class AiTranslateController extends FormSchemaController
{
    private const ALLOWED_DIFF_HTML_ELEMENTS = [
        'del',
        'ins',
        'p',
    ];
    private const STALE_SECURITY_TOKEN_MESSAGE = 'Session timed out, please refresh and try again.';
    private const LOCALISED_RECORD_REQUIRED_MESSAGE = 'AI translate is only available after this page has been'
        . ' localised for the active locale.';

    private static $url_segment = 'ai-translate';

    private static $menu_title = 'AI translate';

    private static $menu_priority = -1;

    private static $url_handlers = [
        'GET schema/$ID' => 'schema',
        'POST translate/$ID' => 'translate',
        'POST apply/$ID' => 'apply',
    ];

    private static $allowed_actions = [
        'schema',
        'translate',
        'apply',
    ];

    /**
     * Returns the boot-time client config consumed by the CMS integration code.
     */
    public function getClientConfig(): array
    {
        $config = parent::getClientConfig();
        $className = 'ai-translate-modal';
        $modalSelector = '.' . implode('.', preg_split('/\s+/', trim($className)));
        $config['form']['aiTranslate'] = [
            'schemaUrl' => $this->Link('schema'),
            'translateUrl' => $this->Link('translate'),
            'applyUrl' => $this->Link('apply'),
            'className' => $className,
            'modalClassName' => $className,
            'modalSelector' => $modalSelector,
            'size' => 'xl',
        ];
        return $config;
    }

    /**
     * Returns the form schema and modal metadata for one record.
     */
    public function schema(HTTPRequest $request): HTTPResponse
    {
        try {
            $record = $this->resolveRecordFromRequest($request);
            $targetLocale = $this->resolveTargetLocale();
            $this->ensureRecordCanBeTranslatedInLocale($record, $targetLocale);
            $form = AiTranslateForm::createForRecord($this, $record, $targetLocale);
            return $this->getSchemaResponse(
                $request->getURL(),
                $form,
                null,
                ['meta' => $this->buildSchemaMeta($record, $form, $targetLocale)]
            );
        } catch (HTTPResponse_Exception $exception) {
            return $exception->getResponse();
        }
    }

    /**
     * Generates structured translation suggestions for the active locale.
     */
    public function translate(HTTPRequest $request): HTTPResponse
    {
        try {
            $this->requireValidSecurityToken($request);
            $record = $this->resolveRecordFromRequest($request);
            $targetLocale = $this->resolveTargetLocale();
            $this->ensureRecordCanBeTranslatedInLocale($record, $targetLocale);
            $retryAfter = $this->getTranslateRateLimiter()->consumeRequest(
                $request->getSession(),
                $this->getCurrentMemberId(),
                (int) $record->ID
            );
            if ($retryAfter > 0) {
                return $this->buildRateLimitedTranslateResponse($retryAfter);
            }
            $suggestions = $this->getGenerationService()->generateForRecord($record, $targetLocale);
        } catch (HTTPResponse_Exception $exception) {
            return $exception->getResponse();
        } catch (AIProviderException $exception) {
            $this->logProviderException($exception, $record);
            return $this->jsonResponse([
                'error' => $this->getProviderErrorMessage($exception),
            ], 500);
        }
        if ($suggestions === null) {
            return $this->jsonResponse([
                'error' => AiTranslateForm::NO_CONTENT_MESSAGE,
            ], 400);
        }
        return $this->jsonResponse([
            'alreadyMatchesLocale' => $suggestions->alreadyMatchesLocale,
            'suggestions' => array_map(
                fn(TranslationSuggestion $suggestion): array => $this->serialiseSuggestion($suggestion),
                $suggestions->suggestions
            ),
        ]);
    }

    /**
     * Applies the selected translation suggestions back to target-locale draft content.
     */
    public function apply(HTTPRequest $request): HTTPResponse
    {
        try {
            $this->requireValidSecurityToken($request);
            $record = $this->resolveRecordFromRequest($request);
            $targetLocale = $this->resolveTargetLocale();
            $this->ensureRecordCanBeTranslatedInLocale($record, $targetLocale);
            $suggestions = $this->resolveApplySuggestionsFromRequest($request);
            $result = $this->applySuggestionsInLocale($record, $suggestions, $targetLocale);
            return $this->jsonResponse([
                'appliedCount' => $result['appliedCount'],
                'skippedCount' => $result['skippedCount'],
                'reloadRequired' => $result['appliedCount'] > 0,
            ]);
        } catch (HTTPResponse_Exception $exception) {
            return $exception->getResponse();
        }
    }

    /**
     * Builds the modal metadata attached to the schema response.
     */
    private function buildSchemaMeta(DataObject $record, Form $form, Locale $targetLocale): array
    {
        $sourceLocale = Locale::getDefault();
        $targetLocaleLabel = $this->getLocaleLabelService()->getLanguageLabel($targetLocale);
        $sourceLocaleLabel = $sourceLocale ? $this->getLocaleLabelService()->getLanguageLabel($sourceLocale) : '';
        $isDefaultLocale = $this->isDefaultLocale($targetLocale);
        return [
            'aiTranslate' => [
                'title' => sprintf(
                    'Translate to %s with AI',
                    $this->getLocaleLabelService()->getModalLanguageLabel($targetLocale)
                ),
                'record' => [
                    'id' => $record->ID,
                    'fqcn' => $record->ClassName,
                ],
                'locale' => [
                    'source' => [
                        'code' => $sourceLocale ? (string) $sourceLocale->Locale : '',
                        'title' => $sourceLocaleLabel,
                    ],
                    'target' => [
                        'code' => (string) $targetLocale->Locale,
                        'title' => $targetLocaleLabel,
                    ],
                ],
                'messages' => [
                    'alreadyMatchesLocale' => AiTranslateForm::ALREADY_MATCHES_LOCALE_MESSAGE,
                    'draftNotice' => AiTranslateForm::DRAFT_NOTICE,
                    'emptyState' => AiTranslateForm::EMPTY_STATE_MESSAGE,
                    'noContent' => AiTranslateForm::NO_CONTENT_MESSAGE,
                    'translateSuccess' => AiTranslateForm::TRANSLATE_SUCCESS_MESSAGE,
                    'translateFailure' => AiTranslateForm::TRANSLATE_FAILURE_MESSAGE,
                    'applySuccess' => AiTranslateForm::APPLY_SUCCESS_MESSAGE,
                    'applyPartial' => AiTranslateForm::APPLY_PARTIAL_MESSAGE,
                    'applyFailure' => AiTranslateForm::APPLY_FAILURE_MESSAGE,
                ],
                'labels' => [
                    'generate' => AiTranslateForm::GENERATE_BUTTON_LABEL,
                    'regenerate' => AiTranslateForm::REGENERATE_BUTTON_LABEL,
                    'apply' => AiTranslateForm::APPLY_BUTTON_LABEL,
                    'applySuggestion' => AiTranslateForm::APPLY_SUGGESTION_LABEL,
                ],
                'form' => [
                    'name' => $form->getName(),
                    'action' => $form->FormAction(),
                    'fields' => [
                        'draftNotice' => 'AiTranslateDraftNotice',
                        'emptyState' => 'AiTranslateEmptyState',
                    ],
                ],
                'actions' => [
                    'translateUrl' => $this->Link(sprintf(
                        'translate/%d?fqcn=%s',
                        $record->ID,
                        rawurlencode($record->ClassName)
                    )),
                    'applyUrl' => $this->Link(sprintf(
                        'apply/%d?fqcn=%s',
                        $record->ID,
                        rawurlencode($record->ClassName)
                    )),
                ],
                'errors' => [
                    'provider' => [
                        'mode' => $this->shouldExposeProviderErrors() ? 'development' : 'generic',
                        'genericMessage' => AiTranslateForm::PROVIDER_ERROR_MESSAGE,
                    ],
                ],
                'state' => [
                    'supportsApply' => !$isDefaultLocale,
                    'supportsTranslate' => !$isDefaultLocale,
                    'storesResultsServerSide' => false,
                    'isDefaultLocale' => $isDefaultLocale,
                ],
            ],
        ];
    }

    /**
     * Serialises one suggestion and adds the safe server-generated diff preview.
     */
    private function serialiseSuggestion(TranslationSuggestion $suggestion): array
    {
        $payload = $suggestion->toArray();
        $payload['diffHtml'] = $this->buildSuggestionDiffHtml($suggestion);
        return $payload;
    }

    /**
     * Builds the diff preview shown for one suggestion.
     */
    private function buildSuggestionDiffHtml(TranslationSuggestion $suggestion): string
    {
        $sourceContent = $suggestion->contentFormat === 'html'
            ? $this->flattenToParagraphs($suggestion->currentTargetContent)
            : $suggestion->currentTargetContent;
        return $this->sanitiseDiffHtml(
            HtmlDiff::compareHtml(
                $sourceContent,
                $suggestion->suggestedContent,
                $suggestion->contentFormat !== 'html'
            )
        );
    }

    /**
     * Flattens HTML to paragraph-only markup before diff generation.
     */
    private function flattenToParagraphs(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $htmlValue = new HTMLValue($html);
        foreach ($this->getHtmlBodyElements($htmlValue) as $element) {
            $tag = strtolower($element->tagName);
            if ($tag === 'p') {
                $this->stripElementAttributes($element);
                continue;
            }
            $this->unwrapElement($element);
        }
        return $htmlValue->getContent();
    }

    /**
     * Sanitises the diff preview down to safe, predictable markup.
     */
    private function sanitiseDiffHtml(string $diffHtml): string
    {
        if (trim($diffHtml) === '') {
            return '';
        }
        $htmlValue = new HTMLValue($diffHtml);
        XssSanitiser::create()
            ->setKeepInnerHtmlOnRemoveElement(false)
            ->sanitiseHtmlValue($htmlValue);
        $this->stripDisallowedDiffElements($htmlValue);
        $this->stripDiffElementAttributes($htmlValue);
        return $htmlValue->getContent();
    }

    /**
     * Removes any elements that are not allowed in diff previews.
     */
    private function stripDisallowedDiffElements(HTMLValue $htmlValue): void
    {
        foreach ($this->getHtmlBodyElements($htmlValue) as $element) {
            if (in_array(strtolower($element->tagName), AiTranslateController::ALLOWED_DIFF_HTML_ELEMENTS, true)) {
                continue;
            }
            $this->unwrapElement($element);
        }
    }

    /**
     * Removes all remaining element attributes from diff previews.
     */
    private function stripDiffElementAttributes(HTMLValue $htmlValue): void
    {
        foreach ($this->getHtmlBodyElements($htmlValue) as $element) {
            $this->stripElementAttributes($element);
        }
    }

    /**
     * Collects DOM elements from the HTML body before mutation.
     *
     * @return array<int, DOMElement>
     */
    private function getHtmlBodyElements(HTMLValue $htmlValue): array
    {
        $elements = [];
        foreach ($htmlValue->query('//body//*') as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }
        return $elements;
    }

    /**
     * Removes all attributes from a DOM element.
     */
    private function stripElementAttributes(DOMElement $element): void
    {
        while ($element->attributes->length > 0) {
            $attribute = $element->attributes->item(0);
            if ($attribute) {
                $element->removeAttributeNode($attribute);
            }
        }
    }

    /**
     * Removes one element while keeping its child content in place.
     */
    private function unwrapElement(DOMElement $element): void
    {
        $parentNode = $element->parentNode;
        if (!$parentNode) {
            return;
        }
        while ($element->firstChild) {
            $parentNode->insertBefore($element->firstChild, $element);
        }
        $parentNode->removeChild($element);
    }

    /**
     * Normalises the incoming apply payload from JSON or form-encoded requests.
     */
    private function resolveApplySuggestionsFromRequest(HTTPRequest $request): array
    {
        $body = trim((string) $request->getBody());
        $payload = $body !== '' ? json_decode($body, true) : null;
        if (!is_array($payload)) {
            $payload = $request->postVars();
        }
        $suggestions = $payload['suggestions'] ?? null;
        if (!is_array($suggestions)) {
            $this->failRequest(400, 'Invalid apply request payload');
        }
        return $suggestions;
    }

    /**
     * Applies selected suggestions to page fields and owned Elemental blocks.
     */
    private function applySuggestionsToDraft(DataObject $record, array $suggestions, Locale $targetLocale): array
    {
        $rewriteTargetsByKey = $this->getRewriteTargetsByKey($record, $targetLocale);
        $pageElementalAreaIds = $this->getElementalAreaIds($record);
        $pageElementIds = $this->getElementalElementIds($record);
        $resolvedSuggestions = [];
        $seenTargetKeys = [];
        $pageRequiresWrite = false;
        $appliedCount = 0;
        $appliedTargetKeys = [];
        $skippedCount = 0;
        foreach ($suggestions as $index => $suggestion) {
            if (!is_array($suggestion)) {
                $this->logApplySkip($record, 'invalid-payload', $index);
                $skippedCount++;
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
                $seenTargetKeys
            );
            if ($resolvedSuggestion === []) {
                $skippedCount++;
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
                $pageRequiresWrite
            )) {
                $skippedCount++;
                continue;
            }
            $appliedCount++;
            $appliedTargetKeys[] = $resolvedSuggestion['target']->targetKey;
        }
        if ($pageRequiresWrite) {
            $this->writeDraftRecord($record);
        }
        return [
            'appliedCount' => $appliedCount,
            'appliedTargetKeys' => $appliedTargetKeys,
            'skippedCount' => $skippedCount,
        ];
    }

    /**
     * Fails the whole apply request when any selected block target cannot be edited.
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
                $this->getLogger()->warning('AI Translate apply denied by block permissions', [
                    'recordClass' => $record->ClassName,
                    'recordId' => $record->ID,
                    'targetId' => $target->targetId,
                    'targetKey' => $target->targetKey,
                ]);
                $this->failRequest(403, AiTranslateForm::APPLY_FAILURE_MESSAGE);
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
     */
    private function getRewriteTargetsByKey(DataObject $record, Locale $targetLocale): array
    {
        $targetsByKey = [];
        foreach ($this->getContentExtractService()->extractRewriteTargetsForLocale($record, $targetLocale) as $target) {
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
        array &$seenTargetKeys
    ): array {
        $targetKey = trim((string) ($suggestion['targetKey'] ?? ''));
        if ($targetKey === '') {
            $this->logApplySkip($record, 'missing-target-key', $index);
            return [];
        }
        if (isset($seenTargetKeys[$targetKey])) {
            $this->logApplySkip($record, 'duplicate-target', $index, ['targetKey' => $targetKey]);
            return [];
        }
        $suggestedContent = $suggestion['suggestedContent'] ?? null;
        if (!is_string($suggestedContent)) {
            $this->logApplySkip($record, 'missing-suggested-content', $index, ['targetKey' => $targetKey]);
            return [];
        }
        $target = $rewriteTargetsByKey[$targetKey] ?? null;
        if (!$target) {
            $this->logApplySkip(
                $record,
                $this->resolveMissingTargetReason($suggestion, $pageElementalAreaIds, $pageElementIds),
                $index,
                ['targetKey' => $targetKey]
            );
            return [];
        }
        if (!$this->suggestionMatchesTarget($suggestion, $target)) {
            $this->logApplySkip($record, 'target-metadata-mismatch', $index, ['targetKey' => $targetKey]);
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
        bool &$pageRequiresWrite
    ): bool {
        if (TranslationRewriteTarget::isElementTargetType($target->targetType)) {
            return $this->applyElementSuggestion(
                $record,
                $target,
                $suggestedContent,
                $pageElementalAreaIds,
                $index
            );
        }
        return $this->applyPageSuggestion($record, $target, $suggestedContent, $index, $pageRequiresWrite);
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
        bool &$pageRequiresWrite
    ): bool {
        if (!$record->hasField($target->fieldName)) {
            $this->logApplySkip(
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
        int|string $index
    ): bool {
        if (!$target->targetId) {
            $this->logApplySkip($record, 'missing-target-id', $index, ['targetKey' => $target->targetKey]);
            return false;
        }
        $element = BaseElement::get()->setUseCache(false)->byID($target->targetId);
        if (!$element) {
            $this->logApplySkip($record, 'deleted-target', $index, ['targetKey' => $target->targetKey]);
            return false;
        }
        if (!in_array((int) $element->ParentID, $pageElementalAreaIds, true)) {
            $this->logApplySkip($record, 'foreign-target', $index, ['targetKey' => $target->targetKey]);
            return false;
        }
        if (!$element->hasField($target->fieldName)) {
            $this->logApplySkip(
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
     * Logs the reason one apply payload entry was skipped.
     */
    private function logApplySkip(
        DataObject $record,
        string $reason,
        int|string $index,
        array $context = []
    ): void {
        $this->getLogger()->warning('AI Translate apply skipped suggestion', array_merge([
            'reason' => $reason,
            'recordClass' => $record->ClassName,
            'recordId' => $record->ID,
            'suggestionIndex' => $index,
        ], $context));
    }

    /**
     * Resolves the current record and enforces edit access.
     */
    private function resolveRecordFromRequest(HTTPRequest $request): DataObject
    {
        $fqcn = urldecode((string) ($request->getVar('fqcn') ?: $request->param('FQCN')));
        $id = (int) ($request->param('ID') ?: $request->param('ItemID'));
        if ($fqcn === '' || $id <= 0) {
            $this->failRequest(400, 'Invalid request parameters');
        }
        if (!class_exists($fqcn) || !DataObject::has_extension($fqcn, AiTranslateExtension::class)) {
            $this->failRequest(400, 'Invalid record class');
        }
        $record = DataObject::get($fqcn)->byID($id);
        if (!$record) {
            $this->failRequest(404, 'Record not found');
        }
        if (!$record->canEdit()) {
            $this->failRequest(403, 'Access denied');
        }
        return $record;
    }

    /**
     * Resolves the current Fluent target locale from session state.
     */
    private function resolveTargetLocale(): Locale
    {
        $localeCode = (string) FluentState::singleton()->getLocale();
        if ($localeCode === '') {
            $this->failRequest(400, 'Target locale is missing');
        }
        $targetLocale = Locale::get()->filter('Locale', $localeCode)->first();
        if (!$targetLocale) {
            $this->failRequest(400, 'Invalid target locale');
        }
        return $targetLocale;
    }

    /**
     * Rejects translate and apply requests on the default locale.
     */
    private function ensureNonDefaultLocale(Locale $targetLocale): void
    {
        if ($this->isDefaultLocale($targetLocale)) {
            $this->failRequest(400, 'Translations are only available for non-default locales');
        }
    }

    /**
     * Rejects requests when the current locale has not been localised for this record.
     */
    private function ensureRecordCanBeTranslatedInLocale(DataObject $record, Locale $targetLocale): void
    {
        $this->ensureNonDefaultLocale($targetLocale);
        $localeCode = (string) $targetLocale->Locale;
        if ($record->hasMethod('canAiTranslateInLocale') && $record->canAiTranslateInLocale($localeCode)) {
            return;
        }
        $this->failRequest(400, AiTranslateController::LOCALISED_RECORD_REQUIRED_MESSAGE);
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
     * Rejects requests with a missing or stale CSRF token.
     */
    private function requireValidSecurityToken(HTTPRequest $request): void
    {
        if (SecurityToken::inst()->checkRequest($request)) {
            return;
        }
        $this->failRequest(403, AiTranslateController::STALE_SECURITY_TOKEN_MESSAGE);
    }

    private function buildRateLimitedTranslateResponse(int $retryAfter): HTTPResponse
    {
        $response = $this->jsonResponse([
            'error' => $this->getRateLimitErrorMessage($retryAfter),
        ], 429);
        $response->addHeader('Retry-After', (string) $retryAfter);
        return $response;
    }

    private function getCurrentMemberId(): int
    {
        return (int) (Security::getCurrentUser()?->ID ?? 0);
    }

    private function getRateLimitErrorMessage(int $retryAfter): string
    {
        return sprintf(
            'Too many AI translation requests for this page. Please wait %s and try again.',
            $this->formatCooldownDuration($retryAfter)
        );
    }

    private function formatCooldownDuration(int $retryAfter): string
    {
        if ($retryAfter >= 60) {
            $minutes = (int) ceil($retryAfter / 60);
            return sprintf('%d %s', $minutes, $minutes === 1 ? 'minute' : 'minutes');
        }
        return sprintf('%d %s', $retryAfter, $retryAfter === 1 ? 'second' : 'seconds');
    }

    /**
     * Runs the apply flow inside the active target locale after localising Draft when needed.
     */
    private function applySuggestionsInLocale(DataObject $record, array $suggestions, Locale $targetLocale): array
    {
        $sourceTargetsByKey = $this->getSourceDraftTargetsByKey($record);
        $localeCode = (string) $targetLocale->Locale;
        return FluentState::singleton()->withState(
            function (FluentState $state) use (
                $record,
                $suggestions,
                $targetLocale,
                $localeCode,
                $sourceTargetsByKey
            ): array {
                $state->setLocale($localeCode);
                $draftRecord = $this->prepareDraftRecordInCurrentLocale($record, $localeCode);
                $result = $this->applySuggestionsToDraft($draftRecord, $suggestions, $targetLocale);
                $this->restoreSourceDraftTargets($record, $result['appliedTargetKeys'], $sourceTargetsByKey);
                unset($result['appliedTargetKeys']);
                return $result;
            }
        );
    }

    /**
     * Captures the current default-locale draft values keyed by stable target key.
     *
     * @return array<string, TranslationRewriteTarget>
     */
    private function getSourceDraftTargetsByKey(DataObject $record): array
    {
        $defaultLocale = Locale::getDefault();
        if (!$defaultLocale) {
            $this->failRequest(400, 'Default locale is missing');
        }
        $sourceTargetsByKey = [];
        FluentState::singleton()->withState(
            function (FluentState $state) use ($record, $defaultLocale, &$sourceTargetsByKey): void {
                $state->setLocale((string) $defaultLocale->Locale);
                $sourceRecord = $this->loadDraftRecordInCurrentLocale($record);
                $sourceTargets = $this->getContentExtractService()
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
            $this->failRequest(404, 'Record not found');
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
        $defaultLocale = Locale::getDefault();
        if (!$defaultLocale) {
            $this->failRequest(400, 'Default locale is missing');
        }
        $sourceLocaleCode = (string) $defaultLocale->Locale;
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
        $defaultLocale = Locale::getDefault();
        if (!$defaultLocale) {
            $this->failRequest(400, 'Default locale is missing');
        }
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

    /**
     * Returns the translation generation service.
     */
    private function getGenerationService(): TranslationGenerationService
    {
        return Injector::inst()->get(TranslationGenerationService::class);
    }

    private function getTranslateRateLimiter(): AiTranslateRateLimiter
    {
        return Injector::inst()->get(AiTranslateRateLimiter::class);
    }

    /**
     * Returns the content extraction service.
     */
    private function getContentExtractService(): ContentExtractService
    {
        return Injector::inst()->get(ContentExtractService::class);
    }

    /**
     * Returns the shared locale label formatter.
     */
    private function getLocaleLabelService(): LocaleLabelService
    {
        return Injector::inst()->get(LocaleLabelService::class);
    }

    /**
     * Chooses the provider error message that is safe to expose.
     */
    private function getProviderErrorMessage(AIProviderException $exception): string
    {
        if ($this->shouldExposeProviderErrors()) {
            return $exception->getMessage();
        }
        return AiTranslateForm::PROVIDER_ERROR_MESSAGE;
    }

    /**
     * Limits raw provider errors to development requests outside PHPUnit.
     */
    private function shouldExposeProviderErrors(): bool
    {
        $runningTests = defined('PHPUNIT_COMPOSER_INSTALL');
        return Director::isDev() && !$runningTests;
    }

    /**
     * Logs the original provider exception with page context.
     */
    private function logProviderException(AIProviderException $exception, DataObject $record): void
    {
        $this->getLogger()->error('AI Translate provider request failed', [
            'exception' => $exception,
            'recordClass' => $record->ClassName,
            'recordId' => $record->ID,
        ]);
    }

    /**
     * Returns the controller logger.
     */
    private function getLogger(): LoggerInterface
    {
        return Injector::inst()->get(LoggerInterface::class);
    }

    /**
     * Builds a JSON response for schema-adjacent endpoints.
     */
    private function jsonResponse(array $body, int $code = 200): HTTPResponse
    {
        return HTTPResponse::create(json_encode($body), $code)
            ->addHeader('Content-Type', 'application/json');
    }

    /**
     * Throws a JSON HTTP error response.
     */
    private function failRequest(int $statusCode, string $message): never
    {
        throw new HTTPResponse_Exception($this->jsonResponse(['error' => $message], $statusCode));
    }
}
