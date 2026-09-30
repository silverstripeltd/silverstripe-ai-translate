<?php

namespace SilverstripeLtd\AiTranslate\Controllers;

use DOMElement;
use Psr\Log\LoggerInterface;
use SilverstripeLtd\AiTranslate\Exceptions\AIProviderException;
use SilverstripeLtd\AiTranslate\Exceptions\TranslationApplyException;
use SilverstripeLtd\AiTranslate\Extensions\AiTranslateExtension;
use SilverstripeLtd\AiTranslate\Forms\AiTranslateForm;
use SilverstripeLtd\AiTranslate\Services\AiTranslateRateLimiter;
use SilverstripeLtd\AiTranslate\Services\LocaleLabelService;
use SilverstripeLtd\AiTranslate\Services\TranslationApplyService;
use SilverstripeLtd\AiTranslate\Services\TranslationGenerationService;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationSuggestion;
use SilverStripe\Admin\FormSchemaController;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\XssSanitiser;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
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
            $result = $this->getApplyService()->applyToDraft($record, $targetLocale, $suggestions);
            return $this->jsonResponse($result->toArray());
        } catch (HTTPResponse_Exception $exception) {
            return $exception->getResponse();
        } catch (TranslationApplyException $exception) {
            return $this->buildApplyExceptionResponse($exception);
        }
    }

    /**
     * Maps an apply failure onto the JSON error response and status code the modal expects.
     */
    private function buildApplyExceptionResponse(TranslationApplyException $exception): HTTPResponse
    {
        if ($exception->getReason() === TranslationApplyException::REASON_PERMISSION_DENIED) {
            return $this->jsonResponse(['error' => AiTranslateForm::APPLY_FAILURE_MESSAGE], 403);
        }
        if ($exception->getReason() === TranslationApplyException::REASON_RECORD_NOT_FOUND) {
            return $this->jsonResponse(['error' => $exception->getMessage()], 404);
        }
        return $this->jsonResponse(['error' => $exception->getMessage()], 400);
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
            $this->failRequest(400, TranslationApplyService::NON_DEFAULT_LOCALE_REQUIRED_MESSAGE);
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
        $this->failRequest(400, TranslationApplyService::LOCALISED_RECORD_REQUIRED_MESSAGE);
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
     * Returns the translation generation service.
     */
    private function getGenerationService(): TranslationGenerationService
    {
        return Injector::inst()->get(TranslationGenerationService::class);
    }

    /**
     * Returns the translation apply service.
     */
    private function getApplyService(): TranslationApplyService
    {
        return Injector::inst()->get(TranslationApplyService::class);
    }

    private function getTranslateRateLimiter(): AiTranslateRateLimiter
    {
        return Injector::inst()->get(AiTranslateRateLimiter::class);
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
