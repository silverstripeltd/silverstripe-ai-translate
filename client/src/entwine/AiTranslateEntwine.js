/* global window */
import React from 'react';
import { createRoot } from 'react-dom/client';
import { loadComponent } from 'lib/Injector';
import {
  createAiTranslateSessionCache,
  hasRecordContext,
} from './aiTranslateSessionCache';
import {
  clearPendingAiTranslateToast,
  consumePendingAiTranslateToast,
  storePendingAiTranslateToast,
} from '../toasts/aiTranslatePendingToast';

const jQuery = window.jQuery || window.$;
const AI_TRANSLATE_RECORD_CLASS_FIELD = 'AiTranslateRecordClass';

/**
 * Finds the CMS content wrapper that owns a toolbar button or edit form node.
 */
const getCmsContent = ($element) => $element.closest('.cms-content');

/**
 * Resolves the main edit form that stores the record context for translate.
 */
const getEditForm = ($element) => getCmsContent($element).find('.cms-edit-form').first();

/**
 * Reads the saved record ID from the current CMS edit form.
 */
const getAiTranslateRecordId = ($element) => parseInt(
  getEditForm($element).find('input[name=ID]').val(),
  10,
);

/**
 * Reads the record class hidden field used to build translate API URLs.
 */
const getAiTranslateRecordClass = ($element) => {
  const value = getEditForm($element).find(`input[name=${AI_TRANSLATE_RECORD_CLASS_FIELD}]`).val();
  return typeof value === 'string' ? value.trim() : '';
};

/**
 * Returns the saved record context when the current form has everything translate needs.
 */
const getAiTranslateRecordContext = ($element) => {
  const fqcn = getAiTranslateRecordClass($element);
  const recordId = getAiTranslateRecordId($element);

  if (!hasRecordContext(fqcn, recordId)) {
    return null;
  }

  return {
    fqcn,
    recordId,
  };
};

/**
 * Resolves the injector context so loaded React components share the right CMS subtree.
 */
const getAiTranslateInjectorContext = ($element) => {
  const cmsContent = getCmsContent($element).attr('id');
  return cmsContent ? { context: cmsContent } : {};
};

/**
 * Reads the saved record context from an already-rendered translate action button.
 */
const getActionRecordContext = ($element) => {
  const fqcn = $element.attr('data-fqcn');
  const recordId = parseInt($element.attr('data-record-id'), 10);

  if (!hasRecordContext(fqcn, recordId)) {
    return null;
  }

  return {
    fqcn,
    recordId,
  };
};

/**
 * Reuses one session cache per CMS content panel to preserve modal state between opens.
 */
const getSharedAiTranslateSessionCache = ($element) => {
  const cmsContent = getCmsContent($element);
  if (!cmsContent.length) {
    return createAiTranslateSessionCache();
  }

  let cache = cmsContent.data('aiTranslateSessionCache');
  if (!cache) {
    cache = createAiTranslateSessionCache();
    cmsContent.data('aiTranslateSessionCache', cache);
  }

  return cache;
};

/**
 * Unmounts and removes the rendered React tree for one entwine host node.
 */
const clearRenderedReactTree = (context) => {
  const root = context.getReactRoot();
  if (root) {
    root.unmount();
    context.setReactRoot(null);
  }

  const container = context.getReactContainer();
  if (container) {
    container.remove();
    context.setReactContainer(null);
  }
};

/**
 * Re-renders translate buttons when edit-form state changes and optionally clears stale results.
 */
const syncTranslateButtons = ($form, activeJQuery, { clearCache = false } = {}) => {
  const cmsContent = $form.closest('.cms-content');
  if (!cmsContent.length) {
    return;
  }

  cmsContent.find('.ai-translate__action').each((index, element) => {
    const $button = activeJQuery(element);
    if (clearCache && typeof $button.clearCachedAiTranslateResult === 'function') {
      $button.clearCachedAiTranslateResult();
    }
    if (typeof $button.renderAiTranslateModal === 'function') {
      $button.renderAiTranslateModal();
    }
  });
};

/**
 * Replays any stored toast after a full CMS reload following an apply action.
 */
const showPendingAiTranslateToast = (activeJQuery) => {
  const toast = consumePendingAiTranslateToast();
  if (!toast) {
    return;
  }

  activeJQuery.noticeAdd({
    text: toast.message,
    type: toast.type,
    stayTime: 5000,
    inEffect: { left: '0', opacity: 'show' },
  });
};

/**
 * Registers the CMS action button and modal entwine bindings.
 */
export const registerEntwine = (jQueryInstance = null) => {
  const activeJQuery = jQueryInstance || jQuery;
  if (!activeJQuery || !activeJQuery.entwine) {
    return;
  }

  activeJQuery.entwine('ss.ai-translate', ($) => {
    $('.js-injector-boot .preview-mode-selector').entwine({
      ReactRoot: null,
      ReactContainer: null,
      Component: null,

      /**
       * Tears down any injected toolbar button React tree for this preview toolbar.
       */
      clearToolbarButton() {
        clearRenderedReactTree(this);
      },

      /**
       * Creates or returns the toolbar placeholder that hosts the translate button.
       */
      getOrCreateToolbarButtonContainer() {
        let container = this.getReactContainer();
        if (container) {
          return container;
        }

        container = $('<span class="ai-translate__placeholder"></span>');
        const metadataPlaceholder = this.find('> .ai-metadata__placeholder').first();
        if (metadataPlaceholder.length) {
          metadataPlaceholder.before(container);
        } else {
          const sharePlaceholder = this.find('> .share-draft-content__placeholder').first();
          if (sharePlaceholder.length) {
            sharePlaceholder.before(container);
          } else {
            const firstChild = this.children().first();
            if (firstChild.length) {
              firstChild.before(container);
            } else {
              this.prepend(container);
            }
          }
        }

        this.setReactContainer(container);

        return container;
      },

      /**
       * Mounts or refreshes the toolbar action button when preview controls appear.
       */
      onmatch() {
        const recordContext = getAiTranslateRecordContext(this);
        if (!recordContext) {
          this.clearToolbarButton();
          this._super();
          return;
        }

        let Component = this.getComponent();
        if (!Component) {
          Component = loadComponent('AiTranslateActionButton', getAiTranslateInjectorContext(this));
          this.setComponent(Component);
        }

        const container = this.getOrCreateToolbarButtonContainer();
        let root = this.getReactRoot();
        if (!root) {
          root = createRoot(container[0]);
          this.setReactRoot(root);
        }

        root.render(
          <Component
            fqcn={recordContext.fqcn}
            recordId={recordContext.recordId}
          />
        );

        this._super();
      },

      /**
       * Unmounts the toolbar button when the preview toolbar is removed.
       */
      onunmatch() {
        this.clearToolbarButton();
        this._super();
      },
    });
  });

  activeJQuery.entwine('ss', ($) => {
    $('.ai-translate__action').entwine({
      ReactRoot: null,
      ReactContainer: null,
      Component: null,

      /**
       * Returns the shared session cache used for modal result persistence in this CMS view.
       */
      getOrCreateAiTranslateSessionCache() {
        return getSharedAiTranslateSessionCache(this);
      },

      /**
       * Reads the cached translate result for this record, if one exists.
       */
      getCachedAiTranslateResult() {
        return this.getOrCreateAiTranslateSessionCache().getResult();
      },

      /**
       * Stores the latest modal result so reopening the modal keeps the last translation.
       */
      setCachedAiTranslateResult(result) {
        this.getOrCreateAiTranslateSessionCache().setResult(result);
      },

      /**
       * Clears any cached translate result when the underlying draft changes.
       */
      clearCachedAiTranslateResult() {
        this.getOrCreateAiTranslateSessionCache().clear();
      },

      /**
       * Replays any pending toast the first time the toolbar action matches.
       */
      onmatch() {
        showPendingAiTranslateToast(activeJQuery);
        this._super();
      },

      /**
       * Checks whether the CMS form has unsaved changes that should block translate actions.
       */
      isAiTranslateFormDirty() {
        const editForm = getEditForm(this);
        return editForm.length > 0 && editForm.hasClass('changed');
      },

      /**
       * Stores the next toast and reloads the CMS after a successful apply.
       */
      reloadAfterApply(toast) {
        const storedToast = storePendingAiTranslateToast(toast);

        try {
          window.location.reload();
        } catch (error) {
          if (storedToast) {
            clearPendingAiTranslateToast();
          }
          throw error;
        }
      },

      /**
       * Mounts the translate modal and wires cached results plus reload handling into it.
       */
      renderAiTranslateModal(createIfMissing = false) {
        const recordContext = getActionRecordContext(this);
        if (!recordContext) {
          return;
        }

        let container = this.getReactContainer();
        if (!container) {
          if (!createIfMissing) {
            return;
          }

          container = $('<div class="ai-translate-modal__container"></div>');
          $('body').append(container);
          this.setReactContainer(container);
        }

        let root = this.getReactRoot();
        if (!root) {
          if (!createIfMissing) {
            return;
          }

          root = createRoot(container[0]);
          this.setReactRoot(root);
        }

        let Component = this.getComponent();
        if (!Component) {
          Component = loadComponent('AiTranslateModal');
          this.setComponent(Component);
        }

        const self = this;
        const handleClosed = () => {
          clearRenderedReactTree(self);
        };

        root.render(
          <Component
            fqcn={recordContext.fqcn}
            recordId={recordContext.recordId}
            initialResult={this.getCachedAiTranslateResult()}
            isFormDirty={this.isAiTranslateFormDirty()}
            onApplied={(toast) => this.reloadAfterApply(toast)}
            onResultChange={(result) => this.setCachedAiTranslateResult(result)}
            onClosed={handleClosed}
          />
        );
      },

      /**
       * Opens the modal when the button has a valid saved record context.
       */
      onclick(e) {
        e.preventDefault();
        if (!getActionRecordContext(this)) {
          activeJQuery.noticeAdd({
            text: 'Save the page before opening AI translate.',
            type: 'warning',
          });
          return false;
        }

        this.renderAiTranslateModal(true);

        return false;
      },

      /**
       * Cleans up any mounted modal instance when the action button leaves the DOM.
       */
      onunmatch() {
        clearRenderedReactTree(this);
      },
    });

    $('.cms-edit-form.changed').entwine({
      /**
       * Clears cached results as soon as the draft diverges from saved content.
       */
      onmatch() {
        syncTranslateButtons(this, activeJQuery, { clearCache: true });
        this._super();
      },
    });

    $('.cms-edit-form:not(.changed)').entwine({
      /**
       * Refreshes modal state when the edit form returns to a clean saved draft.
       */
      onmatch() {
        syncTranslateButtons(this, activeJQuery);
        this._super();
      },
    });
  });
};

registerEntwine();
