/* eslint-env jest */
import React from 'react';
import { act } from '@testing-library/react';
import { createRoot } from 'react-dom/client';
import { loadComponent } from 'lib/Injector';
import { registerEntwine } from '../../src/entwine/AiTranslateEntwine';

jest.mock('react-dom/client', () => ({
  createRoot: jest.fn(),
}), { virtual: true });

jest.mock('lib/Injector', () => ({
  loadComponent: jest.fn(),
}), { virtual: true });

const normaliseSelector = (selector) => (
  selector.startsWith('> ')
    ? `:scope ${selector}`
    : selector
);

const wrapElements = (elements) => {
  const list = elements.filter(Boolean);
  const remove = jest.fn(() => {
    list.forEach((element) => element.remove());
  });
  const dataAccessor = (key, value) => {
    const element = list[0];
    if (!element) {
      return value === undefined ? undefined : null;
    }
    if (!element.__jqueryData) {
      element.__jqueryData = {};
    }
    if (value === undefined) {
      return element.__jqueryData[key];
    }
    element.__jqueryData[key] = value;
    return value;
  };

  return {
    0: list[0],
    length: list.length,
    attr: (name, value) => {
      if (value === undefined) {
        return list[0]?.getAttribute(name);
      }
      list.forEach((element) => element.setAttribute(name, value));
      return value;
    },
    append: (node) => {
      const element = node[0] || node;
      list.forEach((target) => target.appendChild(element));
    },
    before: (node) => {
      const element = node[0] || node;
      list[0]?.before(element);
    },
    children: () => wrapElements(list.flatMap((element) => Array.from(element.children))),
    closest: (selector) => wrapElements(list.map((element) => element.closest(selector))),
    data: dataAccessor,
    each: (callback) => {
      list.forEach((element, index) => callback(index, element));
      return list;
    },
    find: (selector) => wrapElements(
      list.flatMap((element) => Array.from(element.querySelectorAll(normaliseSelector(selector))))
    ),
    first: () => wrapElements(list.slice(0, 1)),
    hasClass: (className) => list[0]?.classList.contains(className) || false,
    prepend: (node) => {
      const element = node[0] || node;
      list.forEach((target) => target.prepend(element));
    },
    remove,
    val: () => list[0]?.value,
  };
};

const withEntwineState = (wrapper) => Object.assign(wrapper, {
  ReactRoot: null,
  ReactContainer: null,
  Component: null,
  _super: jest.fn(),
  getComponent() {
    return this.Component;
  },
  getReactContainer() {
    return this.ReactContainer;
  },
  getReactRoot() {
    return this.ReactRoot;
  },
  setComponent(value) {
    this.Component = value;
  },
  setReactContainer(value) {
    this.ReactContainer = value;
  },
  setReactRoot(value) {
    this.ReactRoot = value;
  },
});

const buildToolbarInstance = (definition, element) => Object.assign(
  withEntwineState(wrapElements([element])),
  {
    clearToolbarButton: definition.clearToolbarButton,
    getOrCreateToolbarButtonContainer: definition.getOrCreateToolbarButtonContainer,
    onmatch: definition.onmatch,
    onunmatch: definition.onunmatch,
  }
);

const buildActionInstance = (definition, element) => Object.assign(
  withEntwineState(wrapElements([element])),
  definition
);

const buildJQuery = (entwineDefinitions, instanceRegistry) => {
  const jQuery = (selector) => {
    if (selector === 'body') {
      return wrapElements([document.body]);
    }
    if (selector instanceof window.HTMLElement) {
      return instanceRegistry.get(selector) || wrapElements([selector]);
    }
    if (typeof selector === 'string' && selector.startsWith('<')) {
      const tagMatch = selector.match(/^<([a-z0-9-]+)/i);
      const classMatch = selector.match(/class="([^"]+)"/);
      const element = document.createElement(tagMatch?.[1] || 'div');
      if (classMatch) {
        element.className = classMatch[1];
      }
      return wrapElements([element]);
    }
    return {
      entwine: (definition) => {
        entwineDefinitions[selector] = definition;
      },
    };
  };
  jQuery.entwine = (namespace, callback) => callback(jQuery);
  jQuery.noticeAdd = jest.fn();
  return jQuery;
};

beforeEach(() => {
  document.body.innerHTML = '';
  window.sessionStorage.clear();
  createRoot.mockReset();
  loadComponent.mockReset();
  const originalLocation = window.location;
  delete window.location;
  window.location = {
    ...originalLocation,
    reload: jest.fn(),
  };
});

test('toolbar entwine inserts a translate placeholder before ai-metadata and renders the action button', () => {
  document.body.innerHTML = `
    <div class="cms-content" id="Form_EditForm">
      <form class="cms-edit-form">
        <input name="ID" value="12" />
        <input name="AiTranslateRecordClass" value="App\\Page" />
      </form>
      <div class="js-injector-boot">
        <div class="preview-mode-selector">
          <span class="ai-metadata__placeholder"></span>
          <span class="share-draft-content__placeholder"></span>
        </div>
      </div>
    </div>
  `;

  const root = { render: jest.fn(), unmount: jest.fn() };
  const ActionButton = () => React.createElement('button', null, 'Translate');
  const entwineDefinitions = {};
  const instanceRegistry = new Map();
  const jQuery = buildJQuery(entwineDefinitions, instanceRegistry);

  createRoot.mockReturnValue(root);
  loadComponent.mockReturnValue(ActionButton);
  registerEntwine(jQuery);

  const definition = entwineDefinitions['.js-injector-boot .preview-mode-selector'];
  const toolbar = buildToolbarInstance(
    definition,
    document.querySelector('.preview-mode-selector')
  );

  definition.onmatch.call(toolbar);

  const selector = document.querySelector('.preview-mode-selector');
  expect(selector.firstElementChild.className).toBe('ai-translate__placeholder');
  expect(selector.children[1].className).toBe('ai-metadata__placeholder');
  expect(loadComponent).toHaveBeenCalledWith('AiTranslateActionButton', { context: 'Form_EditForm' });
  const renderedElement = root.render.mock.calls[0][0];
  expect(renderedElement.props.fqcn).toBe('App\\Page');
  expect(renderedElement.props.recordId).toBe(12);
});

test('action entwine restores cached results on reopen and invalidates them when the form becomes dirty', () => {
  document.body.innerHTML = `
    <div class="cms-content" id="Form_EditForm">
      <form class="cms-edit-form">
        <input name="ID" value="12" />
        <input name="AiTranslateRecordClass" value="App\\Page" />
      </form>
      <button class="ai-translate__action" data-fqcn="App\\Page" data-record-id="12">Translate</button>
    </div>
  `;

  const firstRoot = { render: jest.fn(), unmount: jest.fn() };
  const secondRoot = { render: jest.fn(), unmount: jest.fn() };
  const Modal = () => null;
  const entwineDefinitions = {};
  const instanceRegistry = new Map();
  const jQuery = buildJQuery(entwineDefinitions, instanceRegistry);

  createRoot
    .mockReturnValueOnce(firstRoot)
    .mockReturnValueOnce(secondRoot);
  loadComponent.mockReturnValue(Modal);
  registerEntwine(jQuery);

  const actionDefinition = entwineDefinitions['.ai-translate__action'];
  const changedDefinition = entwineDefinitions['.cms-edit-form.changed'];
  const cleanDefinition = entwineDefinitions['.cms-edit-form:not(.changed)'];
  const actionElement = document.querySelector('.ai-translate__action');
  const actionInstance = buildActionInstance(actionDefinition, actionElement);
  instanceRegistry.set(actionElement, actionInstance);

  actionDefinition.onclick.call(actionInstance, { preventDefault: jest.fn() });
  const firstRender = firstRoot.render.mock.calls[0][0];
  expect(firstRender.props.initialResult).toBeNull();
  expect(firstRender.props.isFormDirty).toBe(false);

  act(() => {
    firstRender.props.onResultChange({
      alreadyMatchesLocale: true,
      suggestions: [
        { targetKey: 'page:title', suggestedContent: 'Mo matou' },
      ],
    });
  });
  act(() => {
    firstRender.props.onClosed();
  });

  actionDefinition.onclick.call(actionInstance, { preventDefault: jest.fn() });
  const reopenedRender = secondRoot.render.mock.calls[0][0];
  expect(reopenedRender.props.initialResult).toEqual({
    alreadyMatchesLocale: true,
    suggestions: [
      { targetKey: 'page:title', suggestedContent: 'Mo matou' },
    ],
  });

  const formElement = document.querySelector('.cms-edit-form');
  formElement.classList.add('changed');
  const changedFormInstance = withEntwineState(wrapElements([formElement]));
  changedDefinition.onmatch.call(changedFormInstance);

  const dirtyRender = secondRoot.render.mock.calls[1][0];
  expect(dirtyRender.props.initialResult).toBeNull();
  expect(dirtyRender.props.isFormDirty).toBe(true);

  formElement.classList.remove('changed');
  const cleanFormInstance = withEntwineState(wrapElements([formElement]));
  cleanDefinition.onmatch.call(cleanFormInstance);

  const cleanRender = secondRoot.render.mock.calls[2][0];
  expect(cleanRender.props.initialResult).toBeNull();
  expect(cleanRender.props.isFormDirty).toBe(false);
});

test('action entwine stores apply toasts for reload and replays them on the next match', () => {
  document.body.innerHTML = `
    <div class="cms-content" id="Form_EditForm">
      <form class="cms-edit-form">
        <input name="ID" value="12" />
        <input name="AiTranslateRecordClass" value="App\\Page" />
      </form>
      <button class="ai-translate__action" data-fqcn="App\\Page" data-record-id="12">Translate</button>
    </div>
  `;

  const root = { render: jest.fn(), unmount: jest.fn() };
  const Modal = () => null;
  const entwineDefinitions = {};
  const instanceRegistry = new Map();
  const jQuery = buildJQuery(entwineDefinitions, instanceRegistry);

  createRoot.mockReturnValue(root);
  loadComponent.mockReturnValue(Modal);
  registerEntwine(jQuery);

  const actionDefinition = entwineDefinitions['.ai-translate__action'];
  const actionElement = document.querySelector('.ai-translate__action');
  const actionInstance = buildActionInstance(actionDefinition, actionElement);
  instanceRegistry.set(actionElement, actionInstance);

  actionDefinition.onclick.call(actionInstance, { preventDefault: jest.fn() });
  const render = root.render.mock.calls[0][0];

  act(() => {
    render.props.onApplied({
      type: 'warning',
      message: 'Some translations could not be applied',
    });
  });

  expect(window.sessionStorage.getItem('ai-translate.pending-toast')).toBe(
    JSON.stringify({
      type: 'warning',
      message: 'Some translations could not be applied',
    })
  );
  expect(window.location.reload).toHaveBeenCalled();

  actionDefinition.onmatch.call(actionInstance);

  expect(jQuery.noticeAdd).toHaveBeenCalledWith(expect.objectContaining({
    text: 'Some translations could not be applied',
    type: 'warning',
  }));
  expect(window.sessionStorage.getItem('ai-translate.pending-toast')).toBeNull();
});
