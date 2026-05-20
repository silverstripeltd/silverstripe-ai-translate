/**
 * Normalises the cached translate result into the expected session shape.
 */
const normaliseResult = (initialValue = null) => {
  if (!initialValue || typeof initialValue !== 'object') {
    return null;
  }

  return {
    alreadyMatchesLocale: initialValue.alreadyMatchesLocale === true,
    suggestions: Array.isArray(initialValue.suggestions) ? initialValue.suggestions : [],
  };
};

/**
 * Creates a lightweight cache for the latest translate result within one CMS view.
 */
export const createAiTranslateSessionCache = (initialValue = null) => {
  let cachedResult = normaliseResult(initialValue);

  return {
    getResult: () => cachedResult,
    setResult: (result) => {
      cachedResult = normaliseResult(result);
      return cachedResult;
    },
    clear: () => {
      cachedResult = null;
      return cachedResult;
    },
  };
};

/**
 * Checks whether the current button points at a saved record that can be queried.
 */
export const hasRecordContext = (fqcn, recordId) => (
  typeof fqcn === 'string'
  && fqcn.trim() !== ''
  && Number.isInteger(recordId)
  && recordId > 0
);
