<?php

namespace SilverstripeLtd\AiTranslate\Forms;

use SilverstripeLtd\AiTranslate\Controllers\AiTranslateController;
use SilverstripeLtd\AiTranslate\Services\LocaleLabelService;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\View\HTML;
use TractorCow\Fluent\Model\Locale;

/**
 * Builds the server-side schema for the translation review modal.
 */
class AiTranslateForm extends Form
{
    public const FORM_NAME_TEMPLATE = 'AiTranslateForm_%s';
    public const DRAFT_NOTICE = 'Translation uses your saved draft content. Save the page to draft before'
        . ' translating if you have unsaved changes.';
    public const EMPTY_STATE_MESSAGE = 'Click the button below to translate the content on this page.';
    public const ALREADY_MATCHES_LOCALE_MESSAGE = 'This page content already matches the target locale.';
    public const GENERATE_BUTTON_LABEL = 'Translate content';
    public const REGENERATE_BUTTON_LABEL = 'Retranslate';
    public const APPLY_BUTTON_LABEL = 'Apply translation';
    public const APPLY_SUGGESTION_LABEL = 'Apply this translation';
    public const TRANSLATE_SUCCESS_MESSAGE = 'Translation generated successfully';
    public const TRANSLATE_FAILURE_MESSAGE = 'Unable to generate translation';
    public const APPLY_SUCCESS_MESSAGE = 'Translations applied to draft content';
    public const APPLY_PARTIAL_MESSAGE = 'Some translations could not be applied';
    public const APPLY_FAILURE_MESSAGE = 'Unable to apply translations';
    public const NO_CONTENT_MESSAGE = 'This page has no content to translate';
    public const PROVIDER_ERROR_MESSAGE = 'There was an error connecting to the AI provider';

    /**
     * Creates the modal form schema for one CMS record and locale.
     */
    public static function createForRecord(
        AiTranslateController $controller,
        DataObject $record,
        Locale $targetLocale
    ): AiTranslateForm {
        $fields = FieldList::create(
            LiteralField::create(
                'AiTranslateDraftNotice',
                AiTranslateForm::renderBanner(AiTranslateForm::DRAFT_NOTICE, 'info')
            ),
            LiteralField::create(
                'AiTranslateEmptyState',
                HTML::createTag(
                    'p',
                    ['class' => 'ai-translate-modal__empty-state'],
                    Convert::raw2xml(AiTranslateForm::EMPTY_STATE_MESSAGE)
                )
            ),
            HiddenField::create('TargetLocale', '', (string) $targetLocale->Locale),
            HiddenField::create(
                'TargetLocaleTitle',
                '',
                Injector::inst()->get(LocaleLabelService::class)->getLanguageLabel($targetLocale)
            )
        );
        $actions = FieldList::create(
            FormAction::create('AiTranslateAction', AiTranslateForm::GENERATE_BUTTON_LABEL)
                ->setAttribute('type', 'button')
                ->setAttribute('data-schema-only', 'true')
        );
        /** @var AiTranslateForm $form */
        $form = AiTranslateForm::create(
            $controller,
            sprintf(AiTranslateForm::FORM_NAME_TEMPLATE, $record->ID),
            $fields,
            $actions
        );
        $form->setFormAction($controller->Link(sprintf(
            'translate/%d?fqcn=%s',
            $record->ID,
            rawurlencode($record->ClassName)
        )));
        $form->addExtraClass('form--no-dividers ai-translate-modal__schema');
        return $form;
    }

    /**
     * Renders a banner element used by the modal schema.
     */
    private static function renderBanner(string $message, string $variant): string
    {
        return HTML::createTag(
            'div',
            ['class' => sprintf('ai-translate-modal__banner ai-translate-modal__banner--%s', $variant)],
            Convert::raw2xml($message)
        );
    }
}
