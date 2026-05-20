import Injector from 'lib/Injector';
import AiTranslateActionButtonComponent from 'components/AiTranslateActionButton';
import AiTranslateModalComponent from 'components/AiTranslateModal';

const registerComponents = () => {
  Injector.component.register('AiTranslateActionButton', AiTranslateActionButtonComponent);
  Injector.component.register('AiTranslateModal', AiTranslateModalComponent);
};

export default registerComponents;
