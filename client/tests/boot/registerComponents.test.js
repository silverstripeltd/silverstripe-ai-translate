/* eslint-env jest */
/* eslint-disable import/first */
jest.mock('lib/Injector', () => ({
  __esModule: true,
  default: {
    component: {
      register: jest.fn(),
    },
  },
}), { virtual: true });

jest.mock('components/AiTranslateActionButton', () => 'AiTranslateActionButton', { virtual: true });
jest.mock('components/AiTranslateModal', () => 'AiTranslateModal', { virtual: true });

import Injector from 'lib/Injector';
import registerComponents from '../../src/boot/registerComponents';

test('registerComponents registers the toolbar button and modal components', () => {
  registerComponents();
  expect(Injector.component.register).toHaveBeenNthCalledWith(1, 'AiTranslateActionButton', 'AiTranslateActionButton');
  expect(Injector.component.register).toHaveBeenNthCalledWith(2, 'AiTranslateModal', 'AiTranslateModal');
});
