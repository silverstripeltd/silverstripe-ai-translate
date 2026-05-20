<?php

namespace SilverstripeLtd\AiTranslate\Services;

use JsonException;
use SilverstripeLtd\AiTranslate\Exceptions\AIProviderException;
use SilverstripeLtd\AiTranslate\Providers\ProviderFactory;
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
    private ContentExtractService $contentExtractService;
    private ProviderFactory $providerFactory;
    private PromptService $promptService;

    /**
     * Builds the generation service with injectable dependencies.
     */
    public function __construct(
        ?ContentExtractService $contentExtractService = null,
        ?ProviderFactory $providerFactory = null,
        ?PromptService $promptService = null
    ) {
        $this->contentExtractService = $contentExtractService ?: Injector::inst()->get(ContentExtractService::class);
        $this->providerFactory = $providerFactory ?: Injector::inst()->get(ProviderFactory::class);
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
        $providerResponse = $this->providerFactory->getProvider()->generate($systemPrompt, $userPrompt);
        return $this->resolveGeneratedResult($providerResponse, $extractedContent);
    }

    /**
     * Resolves the source locale from Fluent's configured default locale.
     */
    private function resolveSourceLocale(): Locale
    {
        $sourceLocale = Locale::getDefault();
        if (!$sourceLocale || !$sourceLocale->exists()) {
            throw new AIProviderException('Default Fluent locale is not configured');
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
                throw new AIProviderException(sprintf(
                    'AI provider response referenced unexpected target %s',
                    $parsedSuggestion->targetKey
                ));
            }
            if ($parsedSuggestion->targetType !== $sourceTarget->targetType) {
                throw new AIProviderException(sprintf(
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
        $orderedSuggestions = [];
        foreach ($extractedContent->sourceRewriteTargets as $rewriteTarget) {
            if (!isset($resolvedSuggestions[$rewriteTarget->targetKey])) {
                throw new AIProviderException(sprintf(
                    'AI provider response missing suggestion for target %s',
                    $rewriteTarget->targetKey
                ));
            }
            $orderedSuggestions[] = $resolvedSuggestions[$rewriteTarget->targetKey];
        }
        return new TranslationGenerationResult(false, $orderedSuggestions);
    }

    /**
     * Parses the raw JSON response from the AI provider.
     *
     * @return array{translationRequired: bool, suggestions: array<int, TranslationSuggestion>}
     */
    private function parseProviderResponse(string $providerResponse): array
    {
        try {
            $decodedResponse = json_decode($providerResponse, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AIProviderException('AI provider response was not valid JSON', false, false, 0, $exception);
        }
        if (!is_array($decodedResponse)) {
            throw new AIProviderException('AI provider response was not a JSON object');
        }
        $translationRequired = $decodedResponse['translationRequired'] ?? null;
        if (!is_bool($translationRequired)) {
            throw new AIProviderException('AI provider response missing translationRequired flag');
        }
        $suggestions = $decodedResponse['suggestions'] ?? null;
        if (!is_array($suggestions)) {
            throw new AIProviderException('AI provider response missing suggestions array');
        }
        if ($translationRequired === false) {
            if ($suggestions !== []) {
                throw new AIProviderException(
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
                throw new AIProviderException(sprintf(
                    'AI provider response suggestion %d was not an object',
                    $index
                ));
            }
            $targetKey = trim((string) ($suggestion['targetKey'] ?? ''));
            if ($targetKey === '') {
                throw new AIProviderException('AI provider response missing suggestion targetKey');
            }
            $targetType = trim((string) ($suggestion['targetType'] ?? ''));
            if ($targetType === '') {
                throw new AIProviderException(sprintf(
                    'AI provider response missing suggestion targetType for %s',
                    $targetKey
                ));
            }
            if (!TranslationRewriteTarget::isValidTargetType($targetType)) {
                throw new AIProviderException(sprintf(
                    'AI provider response returned invalid targetType %s for %s',
                    $targetType,
                    $targetKey
                ));
            }
            $suggestedContent = $suggestion['suggestedContent'] ?? null;
            if (!is_string($suggestedContent) || trim($suggestedContent) === '') {
                throw new AIProviderException(sprintf(
                    'AI provider response missing suggestedContent for %s',
                    $targetKey
                ));
            }
            if (isset($seenTargetKeys[$targetKey])) {
                throw new AIProviderException(sprintf(
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
