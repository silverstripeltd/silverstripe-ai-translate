/* eslint-disable react/no-danger */
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import PropTypes from 'prop-types';
import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';
import {
  Button,
  Modal,
  ModalBody,
  ModalHeader,
  Spinner,
} from 'reactstrap';
import * as toastsActions from 'state/toasts/ToastsActions';
import {
  buildApplyRequestBody,
  buildApplyUrl,
  buildSchemaUrl,
  buildTranslateUrl,
  buildTranslationResult,
  defaultSchemaConfig,
  getApplyHeaders,
  getInitialSelectedTargetKeys,
  getModalConfig,
  getResponseErrorMessage,
  getResultSuggestions,
  getSchemaHeaders,
  getSuggestionHeading,
  resultAlreadyMatchesLocale,
  getTranslateButtonLabel,
  getTranslateHeaders,
  mergeSchemaConfig,
  suggestionHasMeaningfulChange,
} from './aiTranslateModalHelpers';

/**
 * Wraps fetch so every modal request gets consistent JSON parsing.
 */
const fetchJson = async (url, options = {}) => {
  const response = await window.fetch(url, {
    credentials: 'same-origin',
    ...options,
  });

  return {
    response,
    payload: await response.json(),
  };
};

/**
 * Sends one toast through the matching CMS toast channel.
 */
const showToast = (toasts, toast) => {
  if (toast.type === 'warning') {
    toasts.warning(toast.message);
    return;
  }

  if (toast.type === 'error') {
    toasts.error(toast.message);
    return;
  }

  toasts.success(toast.message);
};

/**
 * Renders the translation review modal, handles schema loading, and applies selections.
 */
export const AiTranslateModal = ({
  fqcn,
  recordId,
  initialResult = null,
  isFormDirty = false,
  onApplied = null,
  onClosed = null,
  onResultChange = null,
  actions,
}) => {
  const [isOpen, setIsOpen] = useState(true);
  const [isLoadingSchema, setIsLoadingSchema] = useState(true);
  const [schemaError, setSchemaError] = useState('');
  const [schemaConfig, setSchemaConfig] = useState(defaultSchemaConfig);
  const [isTranslating, setIsTranslating] = useState(false);
  const [isApplying, setIsApplying] = useState(false);
  const [result, setResult] = useState(initialResult || null);
  const [selectedTargetKeys, setSelectedTargetKeys] = useState(() => getInitialSelectedTargetKeys(initialResult));

  const modalConfig = useMemo(() => getModalConfig(), []);
  const schemaUrl = useMemo(() => buildSchemaUrl(fqcn, recordId, modalConfig), [fqcn, modalConfig, recordId]);
  const defaultTranslateUrl = useMemo(
    () => buildTranslateUrl(fqcn, recordId, modalConfig),
    [fqcn, modalConfig, recordId]
  );
  const defaultApplyUrl = useMemo(
    () => buildApplyUrl(fqcn, recordId, modalConfig),
    [fqcn, modalConfig, recordId]
  );

  useEffect(() => {
    setResult(initialResult || null);
    setSelectedTargetKeys(getInitialSelectedTargetKeys(initialResult));
  }, [initialResult]);

  useEffect(() => {
    let isMounted = true;

    /**
     * Loads the schema metadata that drives labels, URLs, and locale labels in the modal.
     */
    const loadSchema = async () => {
      try {
        const { response, payload } = await fetchJson(schemaUrl, {
          headers: getSchemaHeaders(),
        });
        if (!response.ok) {
          throw new Error(getResponseErrorMessage(payload, defaultSchemaConfig.messages.translateFailure));
        }
        if (!isMounted) {
          return;
        }
        setSchemaConfig(mergeSchemaConfig(payload));
        setSchemaError('');
      } catch (error) {
        if (!isMounted) {
          return;
        }
        const message = error?.message || defaultSchemaConfig.messages.translateFailure;
        setSchemaError(message);
        actions.toasts.error(message);
      } finally {
        if (isMounted) {
          setIsLoadingSchema(false);
        }
      }
    };

    loadSchema();

    return () => {
      isMounted = false;
    };
  }, [actions, schemaUrl]);

  /**
   * Closes the modal and notifies the parent action button when teardown is complete.
   */
  const handleClosed = useCallback(() => {
    setIsOpen(false);
    if (typeof onClosed === 'function') {
      onClosed();
    }
  }, [onClosed]);

  /**
   * Runs translation for the saved draft content and replaces the modal result state.
   */
  const handleTranslate = useCallback(async () => {
    setIsTranslating(true);
    try {
      const { response, payload } = await fetchJson(
        schemaConfig.actions?.translateUrl || defaultTranslateUrl,
        {
          method: 'POST',
          headers: getTranslateHeaders(),
        }
      );
      if (!response.ok) {
        const message = getResponseErrorMessage(payload, schemaConfig.messages.translateFailure);
        if (message === schemaConfig.messages.noContent || response.status === 400) {
          actions.toasts.warning(message);
        } else {
          actions.toasts.error(message);
        }
        return;
      }

      const nextResult = buildTranslationResult(payload);
      setResult(nextResult);
      setSelectedTargetKeys(getInitialSelectedTargetKeys(nextResult));
      if (typeof onResultChange === 'function') {
        onResultChange(nextResult);
      }
      if (!nextResult.alreadyMatchesLocale) {
        actions.toasts.success(schemaConfig.messages.translateSuccess);
      }
    } catch (error) {
      actions.toasts.error(error?.message || schemaConfig.messages.translateFailure);
    } finally {
      setIsTranslating(false);
    }
  }, [actions, defaultTranslateUrl, onResultChange, schemaConfig]);

  const suggestions = useMemo(() => getResultSuggestions(result), [result]);
  const alreadyMatchesLocale = useMemo(() => resultAlreadyMatchesLocale(result), [result]);
  const actionableSuggestions = useMemo(
    () => suggestions.filter((suggestion) => suggestionHasMeaningfulChange(suggestion)),
    [suggestions]
  );
  const selectedSuggestionKeys = useMemo(() => new Set(selectedTargetKeys), [selectedTargetKeys]);
  const selectedSuggestions = useMemo(
    () => actionableSuggestions.filter(({ targetKey }) => selectedSuggestionKeys.has(targetKey)),
    [actionableSuggestions, selectedSuggestionKeys]
  );
  const hasSelectableSuggestions = actionableSuggestions.length > 0;
  const hasSelectedSuggestions = selectedSuggestions.length > 0;

  /**
   * Toggles one suggestion in the apply selection list.
   */
  const handleToggleSuggestion = useCallback((targetKey) => {
    setSelectedTargetKeys((currentKeys) => {
      if (currentKeys.includes(targetKey)) {
        return currentKeys.filter((currentKey) => currentKey !== targetKey);
      }

      return [...currentKeys, targetKey];
    });
  }, []);

  /**
   * Applies the selected suggestions back to target-locale draft content.
   */
  const handleApply = useCallback(async () => {
    if (!hasSelectedSuggestions) {
      return;
    }

    try {
      setIsApplying(true);

      const { response, payload } = await fetchJson(schemaConfig.actions?.applyUrl || defaultApplyUrl, {
        method: 'POST',
        headers: getApplyHeaders(),
        body: JSON.stringify(buildApplyRequestBody(selectedSuggestions)),
      });
      if (!response.ok) {
        actions.toasts.error(getResponseErrorMessage(payload, schemaConfig.messages.applyFailure));
        return;
      }

      if ((payload.appliedCount || 0) > 0) {
        const applyToast = (payload.skippedCount || 0) > 0
          ? { type: 'warning', message: schemaConfig.messages.applyPartial }
          : { type: 'success', message: schemaConfig.messages.applySuccess };

        if (payload.reloadRequired !== false && typeof onApplied === 'function') {
          onApplied(applyToast, payload);
        } else {
          showToast(actions.toasts, applyToast);
        }

        return;
      }

      if ((payload.skippedCount || 0) > 0) {
        actions.toasts.warning(schemaConfig.messages.applyPartial);
        return;
      }

      actions.toasts.warning(schemaConfig.messages.noSuggestions);
    } catch (error) {
      actions.toasts.error(error?.message || schemaConfig.messages.applyFailure);
    } finally {
      setIsApplying(false);
    }
  }, [actions.toasts, defaultApplyUrl, hasSelectedSuggestions, onApplied, schemaConfig, selectedSuggestions]);

  const supportsTranslate = schemaConfig.state?.supportsTranslate ?? defaultSchemaConfig.state.supportsTranslate;
  const supportsApply = schemaConfig.state?.supportsApply ?? defaultSchemaConfig.state.supportsApply;
  const isDefaultLocale = schemaConfig.state?.isDefaultLocale ?? defaultSchemaConfig.state.isDefaultLocale;
  const actionsDisabled = isLoadingSchema
    || isTranslating
    || isApplying
    || !!schemaError
    || isFormDirty
    || isDefaultLocale
    || !supportsTranslate;
  const translateDisabled = actionsDisabled || alreadyMatchesLocale;
  const showResult = !isLoadingSchema && !isTranslating && !!result;
  const showApplyAction = supportsApply && showResult && !alreadyMatchesLocale && hasSelectableSuggestions;
  const loadingMessage = isApplying ? 'Applying translations...' : 'Loading...';
  let resultContent = null;
  if (alreadyMatchesLocale) {
    resultContent = (
      <p className="ai-translate-modal__empty-state">{schemaConfig.messages.alreadyMatchesLocale}</p>
    );
  } else if (!hasSelectableSuggestions) {
    resultContent = (
      <p className="ai-translate-modal__empty-state">{schemaConfig.messages.noSuggestions}</p>
    );
  } else {
    resultContent = (
      <div className="ai-translate-modal__suggestions">
        {actionableSuggestions.map((suggestion, index) => {
          const suggestionHeading = getSuggestionHeading(suggestion, index);
          const isSelected = selectedSuggestionKeys.has(suggestion.targetKey);
          const checkboxId = `ai-translate-suggestion-${(suggestion.targetKey || `${index}`)
            .replace(/[^a-zA-Z0-9_-]+/g, '-')}`;

          return (
            <article
              key={suggestion.targetKey || `${suggestionHeading}-${index}`}
              className="ai-translate-modal__suggestion"
            >
              <div className="ai-translate-modal__suggestion-header">
                <div className="ai-translate-modal__suggestion-heading">
                  <h5>{suggestionHeading}</h5>
                </div>

                {supportsApply ? (
                  <div className="ai-translate-modal__suggestion-toggle">
                    <input
                      id={checkboxId}
                      type="checkbox"
                      checked={isSelected}
                      disabled={actionsDisabled}
                      onChange={() => handleToggleSuggestion(suggestion.targetKey)}
                      aria-label={`Apply ${suggestionHeading}`}
                    />
                    <label htmlFor={checkboxId}>{schemaConfig.labels.applySuggestion}</label>
                  </div>
                ) : null}
              </div>

              <section className="ai-translate-modal__suggestion-section">
                {/* Server-generated HtmlDiff output is rendered here for the review preview. */}
                <div
                  aria-label={`Draft diff: ${suggestionHeading}`}
                  className="ai-translate-modal__suggestion-diff"
                  dangerouslySetInnerHTML={{ __html: suggestion.diffHtml || '' }}
                />
              </section>
            </article>
          );
        })}
      </div>
    );
  }
  const closeButton = (
    <button
      type="button"
      className="btn btn-close btn--icon-xl btn--no-text modal__close-button"
      aria-label="Close"
      title="Close"
      onClick={handleClosed}
    >
      <span aria-hidden="true" className="font-icon-cancel btn__icon" />
    </button>
  );

  return (
    <Modal
      isOpen={isOpen}
      toggle={handleClosed}
      size={modalConfig.size}
      className={modalConfig.className}
      modalClassName={modalConfig.modalClassName}
    >
      <ModalHeader close={closeButton}>{schemaConfig.title}</ModalHeader>
      <ModalBody>
        {schemaError ? (
          <div className="ai-translate-modal__banner ai-translate-modal__banner--error">
            {schemaError}
          </div>
        ) : null}

        {!schemaError ? (
          <>
            {isFormDirty ? (
              <div className="ai-translate-modal__banner ai-translate-modal__banner--warning">
                {schemaConfig.messages.draftNotice}
              </div>
            ) : null}

            {!isLoadingSchema && !showResult ? (
              <p className="ai-translate-modal__empty-state">{schemaConfig.messages.emptyState}</p>
            ) : null}

            <div className="ai-translate-modal__actions">
              <Button
                color="info"
                type="button"
                onClick={handleTranslate}
                disabled={translateDisabled}
              >
                {getTranslateButtonLabel(result, schemaConfig)}
              </Button>
            </div>

            {isLoadingSchema || isTranslating || isApplying ? (
              <div className="ai-translate-modal__loading" role="status">
                <Spinner size="sm" />
                <span>{loadingMessage}</span>
              </div>
            ) : null}

            {showResult ? (
              <div className="ai-translate-modal__result">
                {resultContent}
              </div>
            ) : null}

            {showApplyAction ? (
              <div className="ai-translate-modal__footer-actions">
                <Button
                  color="info"
                  type="button"
                  onClick={handleApply}
                  disabled={actionsDisabled || !hasSelectedSuggestions}
                >
                  {schemaConfig.labels.apply}
                </Button>
              </div>
            ) : null}
          </>
        ) : null}
      </ModalBody>
    </Modal>
  );
};

AiTranslateModal.propTypes = {
  fqcn: PropTypes.string.isRequired,
  recordId: PropTypes.number.isRequired,
  initialResult: PropTypes.shape({
    alreadyMatchesLocale: PropTypes.bool,
    suggestions: PropTypes.arrayOf(PropTypes.shape({
      contentFormat: PropTypes.string,
      currentTargetContent: PropTypes.string,
      diffHtml: PropTypes.string,
      fieldLabel: PropTypes.string,
      fieldName: PropTypes.string,
      sourceLocaleContent: PropTypes.string,
      suggestedContent: PropTypes.string,
      targetId: PropTypes.number,
      targetKey: PropTypes.string,
      targetTitle: PropTypes.string,
      targetType: PropTypes.string,
    })),
  }),
  isFormDirty: PropTypes.bool,
  onApplied: PropTypes.func,
  onClosed: PropTypes.func,
  onResultChange: PropTypes.func,
  actions: PropTypes.shape({
    toasts: PropTypes.shape({
      error: PropTypes.func.isRequired,
      success: PropTypes.func.isRequired,
      warning: PropTypes.func.isRequired,
    }).isRequired,
  }).isRequired,
};

/**
 * Wires CMS toast actions into the modal component props.
 */
const mapDispatchToProps = (dispatch) => ({
  actions: {
    toasts: bindActionCreators(toastsActions, dispatch),
  },
});

export default connect(null, mapDispatchToProps)(AiTranslateModal);
