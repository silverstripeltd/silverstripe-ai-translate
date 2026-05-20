/* eslint-env jest */
/* eslint-disable import/first */
jest.mock('lib/Config', () => ({
  __esModule: true,
  default: {
    get: jest.fn((key) => (key === 'SecurityID' ? 'security-token' : '')),
    getSection: jest.fn(() => ({
      form: {
        aiTranslate: {
          schemaUrl: '/admin/ai-translate/schema',
          translateUrl: '/admin/ai-translate/translate',
          applyUrl: '/admin/ai-translate/apply',
        },
      },
    })),
  },
}), { virtual: true });

jest.mock('lib/urls', () => ({
  joinUrlPaths: (...parts) => parts.join('/'),
}), { virtual: true });

import {
  buildApplyRequestBody,
  buildTranslationResult,
  buildApplyUrl,
  buildSchemaUrl,
  buildTranslateUrl,
  getApplyHeaders,
  getSchemaHeaders,
  getSuggestionHeading,
  getTranslateButtonLabel,
  getTranslateHeaders,
  mergeSchemaConfig,
  resultAlreadyMatchesLocale,
  suggestionHasMeaningfulChange,
} from '../../src/components/aiTranslateModalHelpers';

test('builds record URLs and request headers from controller config', () => {
  expect(buildSchemaUrl('App\\Page', 12)).toBe('/admin/ai-translate/schema/12?fqcn=App%5CPage');
  expect(buildTranslateUrl('App\\Page', 12)).toBe('/admin/ai-translate/translate/12?fqcn=App%5CPage');
  expect(buildApplyUrl('App\\Page', 12)).toBe('/admin/ai-translate/apply/12?fqcn=App%5CPage');
  expect(getSchemaHeaders()).toEqual({
    'X-FormSchema-Request': 'schema,state',
  });
  expect(getTranslateHeaders()).toEqual({
    Accept: 'application/json',
    'X-SecurityID': 'security-token',
  });
  expect(getApplyHeaders()).toEqual({
    Accept: 'application/json',
    'X-SecurityID': 'security-token',
    'Content-Type': 'application/json',
  });
});

test('merges schema defaults with server overrides', () => {
  expect(mergeSchemaConfig({
    meta: {
      aiTranslate: {
        title: 'Translate to french with AI',
        messages: {
          noSuggestions: 'Nothing useful came back.',
        },
        locale: {
          source: {
            title: 'English',
          },
          target: {
            title: 'French',
          },
        },
        state: {
          supportsApply: true,
          supportsTranslate: true,
        },
      },
    },
  })).toEqual(expect.objectContaining({
    title: 'Translate to french with AI',
    messages: expect.objectContaining({
      alreadyMatchesLocale: 'This page content already matches the target locale.',
      noSuggestions: 'Nothing useful came back.',
      applyFailure: 'Unable to apply translations',
    }),
    locale: expect.objectContaining({
      source: expect.objectContaining({
        title: 'English',
      }),
      target: expect.objectContaining({
        title: 'French',
      }),
    }),
    state: expect.objectContaining({
      supportsApply: true,
      supportsTranslate: true,
    }),
  }));
});

test('normalises translate results with the already-matches-locale flag', () => {
  const result = buildTranslationResult({
    alreadyMatchesLocale: true,
    suggestions: [],
  });

  expect(result).toEqual({
    alreadyMatchesLocale: true,
    suggestions: [],
  });
  expect(resultAlreadyMatchesLocale(result)).toBe(true);
  expect(resultAlreadyMatchesLocale({ suggestions: [] })).toBe(false);
});

test('getTranslateButtonLabel switches between initial and iterative labels', () => {
  expect(getTranslateButtonLabel(null)).toBe('Translate content');
  expect(getTranslateButtonLabel({ suggestions: [] })).toBe('Retranslate');
});

test('detects meaningful suggestion changes from diff markup or fallback content comparison', () => {
  expect(suggestionHasMeaningfulChange({
    diffHtml: '<p><ins>Added</ins></p>',
  })).toBe(true);
  expect(suggestionHasMeaningfulChange({
    diffHtml: '',
    currentTargetContent: 'Current title',
    suggestedContent: 'Current title',
    contentFormat: 'text',
  })).toBe(false);
  expect(suggestionHasMeaningfulChange({
    diffHtml: '',
    currentTargetContent: '<p>Current</p>',
    suggestedContent: '<p>Suggested</p>',
    contentFormat: 'html',
  })).toBe(true);
});

test('builds review headings and strips transient data from apply payloads', () => {
  expect(getSuggestionHeading({
    targetType: 'page_title',
  }, 0)).toBe('Page title');
  expect(getSuggestionHeading({
    targetType: 'element_text',
    targetId: 42,
    targetTitle: 'Feature panel',
    fieldLabel: 'Summary',
  }, 0)).toBe('Content block #42 - Feature panel - Summary');

  expect(buildApplyRequestBody([
    {
      targetKey: 'page:title',
      targetType: 'page_title',
      targetId: 12,
      fieldName: 'Title',
      suggestedContent: 'Mo matou',
      diffHtml: '<p>ignored</p>',
      sourceLocaleContent: 'About us',
      currentTargetContent: 'Current',
    },
  ])).toEqual({
    suggestions: [
      {
        targetKey: 'page:title',
        targetType: 'page_title',
        targetId: 12,
        fieldName: 'Title',
        suggestedContent: 'Mo matou',
        apply: true,
      },
    ],
  });
});
