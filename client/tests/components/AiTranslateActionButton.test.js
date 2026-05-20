/* eslint-env jest */
/* eslint-disable import/first */
jest.mock('components/Button/Button', () => {
  const React = jest.requireActual('react');

  return ({ children, className = '', color, icon, ...props }) => React.createElement(
    'button',
    {
      ...props,
      className: `btn ${color ? `btn-${color}` : ''} ${className}`.trim(),
    },
    icon ? React.createElement('span', { className: `btn__icon font-icon-${icon}`, 'aria-hidden': 'true' }) : null,
    children,
  );
}, { virtual: true });

import React from 'react';
import { render, screen } from '@testing-library/react';
import { AiTranslateActionButton } from '../../src/components/AiTranslateActionButton';

test('renders a secondary preview toolbar button with translate labelling', () => {
  const { container } = render(
    <AiTranslateActionButton
      fqcn={'App\\Page'}
      recordId={9}
    />
  );

  const button = screen.getByRole('button', { name: 'Translate' });

  expect(button.className).toContain('ai-translate__action');
  expect(button.className).toContain('ai-translate-toolbar__button');
  expect(button.className).toContain('btn-secondary');
  expect(button.getAttribute('data-fqcn')).toBe('App\\Page');
  expect(button.getAttribute('data-record-id')).toBe('9');
  expect(button.getAttribute('title')).toBe('Translate');
  expect(container.querySelector('.font-icon-globe')).not.toBeNull();
});
