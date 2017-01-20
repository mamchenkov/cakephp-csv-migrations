<?php
namespace CsvMigrations\FieldHandlers;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use Cake\View\Helper\IdGeneratorTrait;
use CsvMigrations\FieldHandlers\RelatedFieldHandler;

class HasManyFieldHandler extends RelatedFieldHandler
{
    /**
     * Action name for html link
     */
    const LINK_ACTION = 'view';

    /**
     * Html input markup
     */
    const HTML_INPUT = '
        <div class="input-group select2-bootstrap-prepend select2-bootstrap-append">
            <span class="input-group-addon" title="%s"><span class="fa fa-%s"></span></span>%s
        </div>';

    /**
     * Html embedded button markup
     */
    const HTML_EMBEDDED_BTN = '
        <div class="input-group-btn">
            <button class="btn btn-primary" title="Link record" type="submit">
                <i class="fa fa-link" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-default" data-toggle="modal" data-target="#%s_modal">
                <i class="fa fa-plus" aria-hidden="true"></i>
            </button>
        </div>';

    /**
     * Method responsible for rendering field's input.
     *
     * @param  string $data    field data
     * @param  array  $options field options
     * @return string          field input
     */
    public function renderInput($data = '', array $options = [])
    {
        $data = $this->_getFieldValueFromData($data);
        $relatedProperties = $this->_getRelatedProperties($options['fieldDefinitions']->getLimit(), $data);

        if (!empty($relatedProperties['dispFieldVal']) && !empty($relatedProperties['config']['parent']['module'])) {
            $relatedParentProperties = $this->_getRelatedParentProperties($relatedProperties);
            if (!empty($relatedParentProperties['dispFieldVal'])) {
                $relatedProperties['dispFieldVal'] = implode(' ' . $this->_separator . ' ', [
                    $relatedParentProperties['dispFieldVal'],
                    $relatedProperties['dispFieldVal']
                ]);
            }
        }

        $fieldName = $this->_getFieldName($options);

        // create select input
        $input = $this->cakeView->Form->input($options['associated_table_name'] . '._ids', [
            'options' => [$data => $relatedProperties['dispFieldVal']],
            'label' => false,
            'id' => $this->field,
            'type' => 'select',
            'title' => $this->_getInputHelp($relatedProperties),
            'data-type' => 'select2',
            'data-display-field' => $relatedProperties['displayField'],
            'escape' => false,
            'data-id' => $this->_domId($fieldName),
            'autocomplete' => 'off',
            'required' => (bool)$options['fieldDefinitions']->getRequired(),
            'data-url' => $this->cakeView->Url->build([
                'prefix' => 'api',
                'plugin' => $relatedProperties['plugin'],
                'controller' => $relatedProperties['controller'],
                'action' => 'lookup.json'
            ]),
            'multiple' => 'multiple',
            'value' => $relatedProperties['dispFieldVal']
        ]);

        // append embedded modal button
        $input .= sprintf(
            static::HTML_EMBEDDED_BTN,
            empty($options['emDataTarget']) ? $this->field : $options['emDataTarget']
        );

        // create input html
        $input = sprintf(
            static::HTML_INPUT,
            $relatedProperties['controller'],
            $this->_getInputIcon($relatedProperties),
            $input
        );

        return $input;
    }

    /**
     * {@inheritDoc}
     */
    public function renderSearchInput(array $options = [])
    {
        return false;
    }
}
