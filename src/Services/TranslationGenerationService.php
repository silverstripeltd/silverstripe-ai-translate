<?php

namespace SilverstripeLtd\AiTranslate\Services;

use SilverstripeLtd\AiCore\Completion\JsonCompletion;
use SilverstripeLtd\AiCore\Completion\SimpleCompletion;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Settings\EnvProviderSettings;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationExtractedContent;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationGenerationResult;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationRewriteTarget;
use SilverstripeLtd\AiTranslate\ValueObjects\TranslationSuggestion;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use TractorCow\Fluent\Model\Locale;

/**
 * Coordinates extraction, prompting, provider calls, and response validation.
 */
class TranslationGenerationService
{
    /**
     * Module prefix of the AI_TRANSLATE_* provider environment variables.
     */
    public const SETTINGS_PREFIX = 'TRANSLATE';

    private ContentExtractService $contentExtractService;
    private SimpleCompletion $completion;
    private PromptService $promptService;

    /**
     * Builds the generation service with injectable dependencies.
     */
    public function __construct(
        ?ContentExtractService $contentExtractService = null,
        ?SimpleCompletion $completion = null,
        ?PromptService $promptService = null
    ) {
        $this->contentExtractService = $contentExtractService ?: Injector::inst()->get(ContentExtractService::class);
        $this->completion = $completion
            ?: SimpleCompletion::create(EnvProviderSettings::forModule(self::SETTINGS_PREFIX));
        $this->promptService = $promptService ?: Injector::inst()->get(PromptService::class);
    }

    /**
     * Generates structured translation suggestions for one record and target locale.
     */
    public function generateForRecord(DataObject $record, Locale $targetLocale): ?TranslationGenerationResult
    {
        if (!$record->exists()) {
            return null;
        }
        $sourceLocale = $this->resolveSourceLocale();
        $extractedContent = $this->contentExtractService->extract($record, $sourceLocale, $targetLocale);
        if ($extractedContent->isEmpty()) {
            return null;
        }
        [$systemPrompt, $userPrompt] = $this->promptService->buildPrompts(
            $extractedContent,
            $sourceLocale,
            $targetLocale
        );
        $providerResponse = $this->completion->complete($systemPrompt, $userPrompt);
        return $this->resolveGeneratedResult($providerResponse, $extractedContent);
    }

    /**
     * Resolves the source locale from Fluent's configured default locale.
     */
    private function resolveSourceLocale(): Locale
    {
        $sourceLocale = Locale::getDefault();
        if (!$sourceLocale || !$sourceLocale->exists()) {
            throw new ProviderException('Default Fluent locale is not configured');
        }
        return $sourceLocale;
    }

    /**
     * Validates provider output against the server-known rewrite targets.
     */
    private function resolveGeneratedResult(
        string $providerResponse,
        TranslationExtractedContent $extractedContent
    ): TranslationGenerationResult {
        $parsedResponse = $this->parseProviderResponse($providerResponse);
        if ($parsedResponse['translationRequired'] === false) {
            return new TranslationGenerationResult(true, []);
        }
        $parsedSuggestions = $parsedResponse['suggestions'];
        $sourceTargetsByKey = [];
        foreach ($extractedContent->sourceRewriteTargets as $rewriteTarget) {
            $sourceTargetsByKey[$rewriteTarget->targetKey] = $rewriteTarget;
        }
        $targetTargetsByKey = [];
        foreach ($extractedContent->targetRewriteTargets as $rewriteTarget) {
            $targetTargetsByKey[$rewriteTarget->targetKey] = $rewriteTarget;
        }
        $resolvedSuggestions = [];
        foreach ($parsedSuggestions as $parsedSuggestion) {
            $sourceTarget = $sourceTargetsByKey[$parsedSuggestion->targetKey] ?? null;
            if (!$sourceTarget) {
                throw new ProviderException(sprintf(
                    'AI provider response referenced unexpected target %s',
                    $parsedSuggestion->targetKey
                ));
            }
            if ($parsedSuggestion->targetType !== $sourceTarget->targetType) {
                throw new ProviderException(sprintf(
                    'AI provider response returned the wrong targetType for %s',
                    $parsedSuggestion->targetKey
                ));
            }
            $targetTarget = $targetTargetsByKey[$parsedSuggestion->targetKey] ?? $sourceTarget->withContent('');
            $resolvedSuggestions[$parsedSuggestion->targetKey] = $parsedSuggestion->withResolvedTargets(
                $sourceTarget,
                $targetTarget
            );
        }
        $orderedSuggestions = $this->orderSuggestions(
            $extractedContent->sourceRewriteTargets,
            $targetTargetsByKey,
            $resolvedSuggestions
        );
        return new TranslationGenerationResult($orderedSuggestions === [], $orderedSuggestions);
    }

    /**
     * Orders suggestions to match the source targets.
     *
     * Models often leave out a target whose current translation is already in the target language,
     * although the prompt asks them to copy it back unchanged. Such a target is kept as written, with
     * no suggestion. A target still blank, or still identical to its source text as a fresh Fluent
     * localisation leaves it, has not been translated yet, so a missing suggestion for it means the
     * response is incomplete.
     *
     * @param array<int, TranslationRewriteTarget> $sourceTargets
     * @param array<string, TranslationRewriteTarget> $targetTargetsByKey
     * @param array<string, TranslationSuggestion> $resolvedSuggestions
     * @return array<int, TranslationSuggestion>
     * @throws ProviderException when a target that has not been translated has no suggestion
     */
    private function orderSuggestions(
        array $sourceTargets,
        array $targetTargetsByKey,
        array $resolvedSuggestions
    ): array {
        $orderedSuggestions = [];
        foreach ($sourceTargets as $sourceTarget) {
            $suggestion = $resolvedSuggestions[$sourceTarget->targetKey] ?? null;
            if ($suggestion) {
                $orderedSuggestions[] = $suggestion;
                continue;
            }
            $targetTarget = $targetTargetsByKey[$sourceTarget->targetKey] ?? null;
            if (!$targetTarget || !$this->hasOwnTranslation($sourceTarget, $targetTarget)) {
                throw new ProviderException(sprintf(
                    'AI provider response missing suggestion for target %s',
                    $sourceTarget->targetKey
                ));
            }
        }
        return $orderedSuggestions;
    }

    /**
     * Whether the target locale holds its own wording for a target, rather than nothing or a copy of
     * the source text.
     */
    private function hasOwnTranslation(
        TranslationRewriteTarget $sourceTarget,
        TranslationRewriteTarget $targetTarget
    ): bool {
        $targetContent = trim($targetTarget->content);
        return $targetContent !== '' && $targetContent !== trim($sourceTarget->content);
    }

    /**
     * Parses the JSON response from the AI provider, tolerating Markdown fences or surrounding prose.
     *
     * @return array{translationRequired: bool, suggestions: array<int, TranslationSuggestion>}
     */
    private function parseProviderResponse(string $providerResponse): array
    {
        $decodedResponse = JsonCompletion::decode($providerResponse);
        if ($decodedResponse === null) {
            throw new ProviderException('AI provider response was not valid JSON');
        }
        if (!is_array($decodedResponse)) {
            throw new ProviderException('AI provider response was not a JSON object');
        }
        $translationRequired = $decodedResponse['translationRequired'] ?? null;
        if (!is_bool($translationRequired)) {
            throw new ProviderException('AI provider response missing translationRequired flag');
        }
        $suggestions = $decodedResponse['suggestions'] ?? null;
        if (!is_array($suggestions)) {
            throw new ProviderException('AI provider response missing suggestions array');
        }
        if ($translationRequired === false) {
            if ($suggestions !== []) {
                throw new ProviderException(
                    'AI provider response must not include suggestions when translationRequired is false'
                );
            }
            return [
                'translationRequired' => false,
                'suggestions' => [],
            ];
        }
        $validatedSuggestions = [];
        $seenTargetKeys = [];
        foreach ($suggestions as $index => $suggestion) {
            if (!is_array($suggestion)) {
                throw new ProviderException(sprintf(
                    'AI provider response suggestion %d was not an object',
                    $index
                ));
            }
            $targetKey = trim((string) ($suggestion['targetKey'] ?? ''));
            if ($targetKey === '') {
                throw new ProviderException('AI provider response missing suggestion targetKey');
            }
            $targetType = trim((string) ($suggestion['targetType'] ?? ''));
            if ($targetType === '') {
                throw new ProviderException(sprintf(
                    'AI provider response missing suggestion targetType for %s',
                    $targetKey
                ));
            }
            if (!TranslationRewriteTarget::isValidTargetType($targetType)) {
                throw new ProviderException(sprintf(
                    'AI provider response returned invalid targetType %s for %s',
                    $targetType,
                    $targetKey
                ));
            }
            $suggestedContent = $suggestion['suggestedContent'] ?? null;
            if (!is_string($suggestedContent) || trim($suggestedContent) === '') {
                throw new ProviderException(sprintf(
                    'AI provider response missing suggestedContent for %s',
                    $targetKey
                ));
            }
            if (isset($seenTargetKeys[$targetKey])) {
                throw new ProviderException(sprintf(
                    'AI provider response contains duplicate suggestions for target %s',
                    $targetKey
                ));
            }
            $seenTargetKeys[$targetKey] = true;
            $validatedSuggestions[] = new TranslationSuggestion(
                $targetKey,
                $targetType,
                trim($suggestedContent)
            );
        }
        return [
            'translationRequired' => true,
            'suggestions' => $validatedSuggestions,
        ];
    }
}
