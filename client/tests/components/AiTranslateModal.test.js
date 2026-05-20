/* eslint-env jest */
/* eslint-disable import/first */
import React from 'react';
import {
  act,
  fireEvent,
  render,
  screen,
  waitFor,
} from '@testing-library/react';

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

jest.mock('redux', () => ({
  bindActionCreators: (actions) => actions,
}), { virtual: true });

jest.mock('react-redux', () => ({
  connect: () => (Component) => Component,
}), { virtual: true });

jest.mock('state/toasts/ToastsActions', () => ({}), { virtual: true });

jest.mock('reactstrap', () => {
  const ReactModule = jest.requireActual('react');

  return {
    Button: ({ children, ...props }) => ReactModule.createElement('button', props, children),
    Modal: ({
      children,
      isOpen,
      className = '',
      modalClassName = '',
    }) => (isOpen ? ReactModule.createElement('div', { className: `${className} ${modalClassName}`.trim() }, children) : null),
    ModalBody: ({ children }) => ReactModule.createElement('div', null, children),
    ModalHeader: ({ children, close }) => ReactModule.createElement('div', null, children, close),
    Spinner: () => ReactModule.createElement('span', null, 'Spinner'),
  };
}, { virtual: true });

import { AiTranslateModal } from '../../src/components/AiTranslateModal';

const buildActions = () => ({
  toasts: {
    error: jest.fn(),
    success: jest.fn(),
    warning: jest.fn(),
  },
});

const buildJsonResponse = (payload, ok = true, status = 200) => ({
  ok,
  status,
  json: jest.fn().mockResolvedValue(payload),
});

beforeEach(() => {
  window.fetch = jest.fn();
});

test('loads schema metadata and shows the empty generate state', async () => {
  const actions = buildActions();
  let resolveSchema;

  window.fetch.mockImplementation(() => new Promise((resolve) => {
    resolveSchema = resolve;
  }));

  render(<AiTranslateModal fqcn="App\\Page" recordId={12} actions={actions} />);

  expect(screen.getByRole('status').textContent).toContain('Loading...');

  await act(async () => {
    resolveSchema(buildJsonResponse({
      meta: {
        aiTranslate: {
          title: 'Translate to te reo maori with AI',
          state: {
            supportsApply: true,
            supportsTranslate: true,
          },
        },
      },
    }));
  });

  expect(await screen.findByText('Click the button below to translate the content on this page.')).not.toBeNull();
  expect(screen.getByText('Translate to te reo maori with AI')).not.toBeNull();
  expect(screen.getByRole('button', { name: 'Close' }).className).toContain('modal__close-button');
  expect(screen.getByRole('button', { name: 'Translate content' }).disabled).toBe(false);
  expect(screen.getByRole('button', { name: 'Translate content' }).getAttribute('color')).toBe('info');
  const emptyState = screen.getByText('Click the button below to translate the content on this page.');
  const actionContainer = screen.getByRole('button', { name: 'Translate content' }).closest('.ai-translate-modal__actions');
  expect(actionContainer?.compareDocumentPosition(emptyState)).toBe(Node.DOCUMENT_POSITION_PRECEDING);
});

test('shows the saved-draft warning and disables actions while the form is dirty', async () => {
  const actions = buildActions();

  window.fetch.mockResolvedValue(buildJsonResponse({
    meta: {
      aiTranslate: {
        state: {
          supportsApply: true,
          supportsTranslate: true,
        },
      },
    },
  }));

  render(
    <AiTranslateModal
      fqcn="App\\Page"
      recordId={12}
      isFormDirty
      actions={actions}
    />
  );

  expect(await screen.findByText(/Translation uses your saved draft content\./)).not.toBeNull();
  expect(screen.getByRole('button', { name: 'Translate content' }).disabled).toBe(true);
});

test('renders one card per meaningful suggestion and strips transient fields from apply payloads', async () => {
  const actions = buildActions();
  const onApplied = jest.fn();
  let resolveApply;

  window.fetch
    .mockResolvedValueOnce(buildJsonResponse({
      meta: {
        aiTranslate: {
          locale: {
            source: {
              title: 'English',
            },
          },
          state: {
            supportsApply: true,
            supportsTranslate: true,
          },
        },
      },
    }))
    .mockImplementationOnce(() => new Promise((resolve) => {
      resolveApply = resolve;
    }));

  render(
    <AiTranslateModal
      fqcn="App\\Page"
      recordId={12}
      initialResult={{
        suggestions: [
          {
            targetKey: 'element:42:html',
            targetType: 'element_html',
            targetId: 42,
            fieldName: 'HTML',
            fieldLabel: 'HTML',
            targetTitle: 'Hero',
            sourceLocaleContent: '<p>Welcome to our site.</p>',
            currentTargetContent: '<p>Nau mai.</p>',
            suggestedContent: '<p>Haere mai ki to matou pae tukutuku.</p>',
            contentFormat: 'html',
            diffHtml: '<p><del>Nau mai.</del><ins>Haere mai ki to matou pae tukutuku.</ins></p>',
          },
          {
            targetKey: 'page:content',
            targetType: 'page_content',
            targetId: 12,
            fieldName: 'Content',
            fieldLabel: 'Content',
            sourceLocaleContent: 'Unchanged',
            currentTargetContent: 'No diff',
            suggestedContent: 'No diff',
            contentFormat: 'text',
            diffHtml: '',
          },
        ],
      }}
      onApplied={onApplied}
      actions={actions}
    />
  );

  expect(await screen.findByText('Content block #42 - Hero')).not.toBeNull();
  expect(screen.queryByText('Page content')).toBeNull();
  expect(screen.queryByText('Source content (English)')).toBeNull();
  expect(screen.queryByText('Draft diff')).toBeNull();
  expect(screen.queryByText('Welcome to our site.')).toBeNull();
  expect(screen.getByLabelText('Draft diff: Content block #42 - Hero')).not.toBeNull();
  expect(screen.getByRole('button', { name: 'Apply translation' }).disabled).toBe(false);
  expect(screen.getByRole('button', { name: 'Apply translation' }).getAttribute('color')).toBe('info');

  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Apply translation' }));
  });

  await waitFor(() => {
    expect(window.fetch).toHaveBeenCalledTimes(2);
  });
  expect(screen.getByText('Content block #42 - Hero')).not.toBeNull();
  expect(screen.getByRole('button', { name: 'Apply translation' }).disabled).toBe(true);
  expect(screen.getByRole('status').textContent).toContain('Applying translations...');

  await act(async () => {
    resolveApply(buildJsonResponse({
      appliedCount: 1,
      skippedCount: 0,
      reloadRequired: true,
    }));
  });

  const [, applyOptions] = window.fetch.mock.calls[1];
  expect(JSON.parse(applyOptions.body)).toEqual({
    suggestions: [
      {
        targetKey: 'element:42:html',
        targetType: 'element_html',
        targetId: 42,
        fieldName: 'HTML',
        suggestedContent: '<p>Haere mai ki to matou pae tukutuku.</p>',
        apply: true,
      },
    ],
  });
  await waitFor(() => {
    expect(onApplied).toHaveBeenCalledWith(
      {
        type: 'success',
        message: 'Translations applied to draft content',
      },
      expect.objectContaining({
        appliedCount: 1,
      })
    );
  });
});

test('generates translation suggestions and caches the latest result through the callback', async () => {
  const actions = buildActions();
  const onResultChange = jest.fn();

  window.fetch
    .mockResolvedValueOnce(buildJsonResponse({
      meta: {
        aiTranslate: {
          locale: {
            source: {
              title: 'English',
            },
          },
          state: {
            supportsApply: true,
            supportsTranslate: true,
          },
        },
      },
    }))
    .mockResolvedValueOnce(buildJsonResponse({
      suggestions: [
        {
          targetKey: 'page:title',
          targetType: 'page_title',
          targetId: 12,
          fieldName: 'Title',
          fieldLabel: 'Page name',
          sourceLocaleContent: 'About us',
          currentTargetContent: 'Mohiotanga',
          suggestedContent: 'Mo matou',
          contentFormat: 'text',
          diffHtml: '<p><del>Mohiotanga</del><ins>Mo matou</ins></p>',
        },
      ],
    }));

  render(
    <AiTranslateModal
      fqcn="App\\Page"
      recordId={12}
      onResultChange={onResultChange}
      actions={actions}
    />
  );

  await screen.findByText('Click the button below to translate the content on this page.');
  fireEvent.click(screen.getByRole('button', { name: 'Translate content' }));

  expect(await screen.findByText('Page title')).not.toBeNull();
  expect(onResultChange).toHaveBeenCalledWith({
    alreadyMatchesLocale: false,
    suggestions: [
      expect.objectContaining({
        targetKey: 'page:title',
      }),
    ],
  });
  expect(actions.toasts.success).toHaveBeenCalledWith('Translation generated successfully');
});

test('shows the already-matches-locale message and disables regeneration when the page is already translated', async () => {
  const actions = buildActions();
  const onResultChange = jest.fn();

  window.fetch
    .mockResolvedValueOnce(buildJsonResponse({
      meta: {
        aiTranslate: {
          state: {
            supportsApply: true,
            supportsTranslate: true,
          },
        },
      },
    }))
    .mockResolvedValueOnce(buildJsonResponse({
      alreadyMatchesLocale: true,
      suggestions: [],
    }));

  render(
    <AiTranslateModal
      fqcn="App\\Page"
      recordId={12}
      onResultChange={onResultChange}
      actions={actions}
    />
  );

  await screen.findByText('Click the button below to translate the content on this page.');
  fireEvent.click(screen.getByRole('button', { name: 'Translate content' }));

  expect(await screen.findByText('This page content already matches the target locale.')).not.toBeNull();
  expect(screen.getByRole('button', { name: 'Retranslate' }).disabled).toBe(true);
  expect(screen.queryByRole('button', { name: 'Apply translation' })).toBeNull();
  expect(onResultChange).toHaveBeenCalledWith({
    alreadyMatchesLocale: true,
    suggestions: [],
  });
  expect(actions.toasts.success).not.toHaveBeenCalled();
});
