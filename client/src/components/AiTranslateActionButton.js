import React from 'react';
import PropTypes from 'prop-types';
import Button from 'components/Button/Button';

/**
 * Renders the CMS toolbar button that opens the translation modal for a record.
 */
export const AiTranslateActionButton = ({
  fqcn,
  recordId,
  title = 'Translate',
  tooltip = 'Translate',
}) => (
  <Button
    type="button"
    color="secondary"
    className="ai-translate__action ai-translate-toolbar__button"
    icon="globe"
    title={tooltip}
    data-fqcn={fqcn}
    data-record-id={recordId}
  >
    {title}
  </Button>
);

AiTranslateActionButton.propTypes = {
  fqcn: PropTypes.string.isRequired,
  recordId: PropTypes.number.isRequired,
  title: PropTypes.string,
  tooltip: PropTypes.string,
};

export default AiTranslateActionButton;
