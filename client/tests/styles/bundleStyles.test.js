/* eslint-env jest */

const fs = require('fs');
const path = require('path');

const readBundleStyles = () => fs.readFileSync(path.resolve(__dirname, '../../src/styles/bundle.scss'), 'utf8');

test('limits the translate modal width to 1280px', () => {
  expect(readBundleStyles()).toMatch(/\.ai-translate-modal\s*\{[\s\S]*?\.modal-dialog\s*\{[\s\S]*?max-width:\s*1280px;/);
});
