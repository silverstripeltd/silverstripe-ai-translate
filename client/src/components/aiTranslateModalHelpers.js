import Config from 'lib/Config';
import { joinUrlPaths } from 'lib/urls';

export const CONTROLLER_CONFIG_KEY = 'SilverstripeLtd\\AiTranslate\\Controllers\\AiTranslateController';

export const defaultSchemaConfig = {
  title: 'Translate with AI',
  locale: {
    source: {
      code: '',
      title: '',
    },
    target: {
      code: '',
      title: '',
    },
  },
  messages: {
    alreadyMatchesLocale: 'This page content already matches the target locale.',
    draftNotice: 'Translation uses your saved draft content. Save the page to draft before translating if you have unsaved changes.',
    emptyState: 'Click the button below to translate the content on this page.',
    noContent: 'This page has no content to translate',
    noSuggestions: 'No translation suggestions were returned for this page.',
    translateSuccess: 'Translation generated successfully',
    translateFailure: 'Unable to generate translation',
    applySuccess: 'Translations applied to draft content',
    applyPartial: 'Some translations could not be applied',
    applyFailure: 'Unable to apply translations',
  },
  labels: {
    generate: 'Translate content',
    regenerate: 'Retranslate',
    apply: 'Apply translation',
    applySuggestion: 'Apply this translation',
    sourceLocale: 'Source content',
    draftDiff: 'Draft diff',
  },
  actions: {
    translateUrl: '',
    applyUrl: '',
  },
  state: {
    supportsApply: false,
    supportsTranslate: false,
    storesResultsServerSide: false,
    isDefaultLocale: false,
  },
  errors: {
    provider: {
      mode: 'generic',
      genericMessage: 'There was an error connecting to the AI provider',
    },
  },
};

/**
 * Reads the controller config that seeds the translate modal.
 */
export const getControllerConfig = () => Config.getSection(CONTROLLER_CONFIG_KEY) || {};

/**
 * Resolves the modal presentation config from defaults and server overrides.
 */
export const getModalConfig = () => ({
  className: 'ai-translate-modal',
  modalClassName: 'ai-translate-modal',
  size: 'xl',
  ...(getControllerConfig().form?.aiTranslate || {}),
});

/**
 * Builds one record-specific controller URL.
 */
const buildRecordActionUrl = (fqcn, recordId, configuredUrl, fallbackUrl) => {
  const base = joinUrlPaths(configuredUrl || fallbackUrl, recordId.toString());
  return `${base}?fqcn=${encodeURIComponent(fqcn)}`;
};

/**
 * Builds the schema endpoint URL for the current record.
 */
export const buildSchemaUrl = (fqcn, recordId, modalConfig = getModalConfig()) => (
  buildRecordActionUrl(fqcn, recordId, modalConfig.schemaUrl, '/admin/ai-translate/schema')
);

/**
 * Builds the translate endpoint URL for the current record.
 */
export const buildTranslateUrl = (fqcn, recordId, modalConfig = getModalConfig()) => (
  buildRecordActionUrl(fqcn, recordId, modalConfig.translateUrl, '/admin/ai-translate/translate')
);

/**
 * Builds the apply endpoint URL for the current record.
 */
export const buildApplyUrl = (fqcn, recordId, modalConfig = getModalConfig()) => (
  buildRecordActionUrl(fqcn, recordId, modalConfig.applyUrl, '/admin/ai-translate/apply')
);

/**
 * Returns the schema request headers expected by FormSchemaController.
 */
export const getSchemaHeaders = () => ({
  'X-FormSchema-Request': 'schema,state',
});

/**
 * Returns the authenticated JSON headers for translation requests.
 */
export const getTranslateHeaders = () => ({
  Accept: 'application/json',
  'X-SecurityID': Config.get('SecurityID') || '',
});

/**
 * Returns the JSON headers for apply requests.
 */
export const getApplyHeaders = () => ({
  ...getTranslateHeaders(),
  'Content-Type': 'application/json',
});

/**
 * Pulls the most useful error message out of varied API response shapes.
 */
export const getResponseErrorMessage = (payload, fallback) => {
  if (payload?.error) {
    return payload.error;
  }
  if (Array.isArray(payload?.errors) && payload.errors[0]?.value) {
    return payload.errors[0].value;
  }
  if (payload?.message) {
    return payload.message;
  }
  return fallback;
};

/**
 * Chooses the initial or repeat translate label based on modal state.
 */
export const getTranslateButtonLabel = (result, schemaConfig = defaultSchemaConfig) => (
  result
    ? schemaConfig.labels?.regenerate || defaultSchemaConfig.labels.regenerate
    : schemaConfig.labels?.generate || defaultSchemaConfig.labels.generate
);

/**
 * Collapses text whitespace so comparisons stay stable.
 */
const normaliseWhitespace = (value) => (
  typeof value === 'string' ? value.replace(/\s+/g, ' ').trim() : ''
);

/**
 * Detects whether a suggestion actually changes the current target draft content.
 */
export const suggestionHasMeaningfulChange = (suggestion) => {
  const diffHtml = typeof suggestion?.diffHtml === 'string' ? suggestion.diffHtml : '';
  if (/<(?:del|ins)\b/i.test(diffHtml)) {
    return true;
  }
  if (diffHtml !== '') {
    return false;
  }

  const isHtmlContent = suggestion?.contentFormat === 'html';
  const currentValue = typeof suggestion?.currentTargetContent === 'string'
    ? suggestion.currentTargetContent
    : '';
  const suggestedValue = typeof suggestion?.suggestedContent === 'string'
    ? suggestion.suggestedContent
    : '';
  const currentContent = isHtmlContent ? currentValue.trim() : normaliseWhitespace(currentValue);
  const suggestedContent = isHtmlContent ? suggestedValue.trim() : normaliseWhitespace(suggestedValue);

  return currentContent !== suggestedContent;
};

/**
 * Normalises the result suggestions field to a predictable array.
 */
export const getResultSuggestions = (result) => (
  Array.isArray(result?.suggestions) ? result.suggestions : []
);

/**
 * Reports whether the last translate result said the page already matches the locale.
 */
export const resultAlreadyMatchesLocale = (result) => result?.alreadyMatchesLocale === true;

/**
 * Shapes the translate response into the session-cache result contract.
 */
export const buildTranslationResult = (payload) => ({
  alreadyMatchesLocale: payload?.alreadyMatchesLocale === true,
  suggestions: getResultSuggestions(payload),
});

/**
 * Seeds the selected suggestion list from actionable suggestions in a result.
 */
export const getInitialSelectedTargetKeys = (result) => (
  getResultSuggestions(result)
    .filter((suggestion) => suggestionHasMeaningfulChange(suggestion))
    .map(({ targetKey }) => targetKey)
    .filter((targetKey) => typeof targetKey === 'string' && targetKey.trim() !== '')
);

/**
 * Chooses the best available field label for a suggestion heading.
 */
const getSuggestionFieldLabel = (suggestion, index) => (
  suggestion?.fieldLabel || suggestion?.fieldName || `Field ${index + 1}`
);

/**
 * Appends one unique heading fragment when it has meaningful content.
 */
const appendHeadingPart = (parts, value) => {
  const trimmedValue = typeof value === 'string' ? value.trim() : '';
  if (!trimmedValue) {
    return parts;
  }
  if (parts.some((part) => part.toLowerCase() === trimmedValue.toLowerCase())) {
    return parts;
  }
  return [...parts, trimmedValue];
};

/**
 * Builds the human-friendly heading shown above each translation suggestion.
 */
export const getSuggestionHeading = (suggestion, index) => {
  switch (suggestion?.targetType) {
    case 'page_title':
      return 'Page title';
    case 'page_content':
      return 'Page content';
    case 'element_html':
    case 'element_text': {
      const fieldLabel = getSuggestionFieldLabel(suggestion, index);
      const shouldIncludeFieldLabel = suggestion?.targetType !== 'element_html'
        || !['html', 'content'].includes(fieldLabel.toLowerCase());
      let headingParts = [`Content block #${suggestion?.targetId || index + 1}`];

      headingParts = appendHeadingPart(headingParts, suggestion?.targetTitle);
      if (shouldIncludeFieldLabel) {
        headingParts = appendHeadingPart(headingParts, fieldLabel);
      }

      return headingParts.join(' - ');
    }
    default:
      return getSuggestionFieldLabel(suggestion, index);
  }
};

/**
 * Builds a plain-text source snippet for the review card.
 */
export const getSuggestionReferenceContent = (suggestion) => {
  const sourceValue = typeof suggestion?.sourceLocaleContent === 'string'
    ? suggestion.sourceLocaleContent
    : '';
  if (suggestion?.contentFormat !== 'html') {
    return sourceValue.trim();
  }

  return sourceValue
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
};

/**
 * Builds the minimal apply payload expected by the server.
 */
export const buildApplyRequestBody = (selectedSuggestions) => ({
  suggestions: selectedSuggestions.map((suggestion) => {
    const applySuggestion = {
      targetKey: suggestion.targetKey,
      suggestedContent: suggestion.suggestedContent,
      apply: true,
    };

    if (typeof suggestion.targetType === 'string' && suggestion.targetType.trim() !== '') {
      applySuggestion.targetType = suggestion.targetType;
    }
    if (typeof suggestion.fieldName === 'string' && suggestion.fieldName.trim() !== '') {
      applySuggestion.fieldName = suggestion.fieldName;
    }
    if (Number.isInteger(suggestion.targetId) && suggestion.targetId > 0) {
      applySuggestion.targetId = suggestion.targetId;
    }

    return applySuggestion;
  }),
});

/**
 * Merges server schema metadata onto client defaults for resilient rendering.
 */
export const mergeSchemaConfig = (schemaPayload) => {
  const serverConfig = schemaPayload?.meta?.aiTranslate || {};
  return {
    ...defaultSchemaConfig,
    ...serverConfig,
    locale: {
      ...defaultSchemaConfig.locale,
      ...(serverConfig.locale || {}),
      source: {
        ...defaultSchemaConfig.locale.source,
        ...(serverConfig.locale?.source || {}),
      },
      target: {
        ...defaultSchemaConfig.locale.target,
        ...(serverConfig.locale?.target || {}),
      },
    },
    messages: {
      ...defaultSchemaConfig.messages,
      ...(serverConfig.messages || {}),
    },
    labels: {
      ...defaultSchemaConfig.labels,
      ...(serverConfig.labels || {}),
    },
    actions: {
      ...defaultSchemaConfig.actions,
      ...(serverConfig.actions || {}),
    },
    state: {
      ...defaultSchemaConfig.state,
      ...(serverConfig.state || {}),
    },
    errors: {
      ...defaultSchemaConfig.errors,
      ...(serverConfig.errors || {}),
      provider: {
        ...defaultSchemaConfig.errors.provider,
        ...(serverConfig.errors?.provider || {}),
      },
    },
  };
};
