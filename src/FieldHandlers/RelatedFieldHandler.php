<?php
namespace CsvMigrations\FieldHandlers;

use Cake\Core\Configure;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use Cake\View\Helper\IdGeneratorTrait;
use CsvMigrations\FieldHandlers\BaseFieldHandler;

class RelatedFieldHandler extends BaseFieldHandler
{
    use IdGeneratorTrait;
    use RelatedFieldTrait;

    /**
     * Field type
     */
    const DB_FIELD_TYPE = 'uuid';

    /**
     * Action name for html link
     */
    const LINK_ACTION = 'view';

    /**
     * Suffix for label field
     */
    const LABEL_FIELD_SUFFIX = '_label';

    /**
     * Flag for rendering value without url
     */
    const RENDER_PLAIN_VALUE = 'plain';

    const HTML_SEARCH_INPUT = '
        <div class="input-group">
            <span class="input-group-addon" title="%s"><span class="fa fa-%s"></span></span>%s
        </div>';

    /**
     * Method responsible for rendering field's input.
     *
     * @param  mixed  $table   name or instance of the Table
     * @param  string $field   field name
     * @param  string $data    field data
     * @param  array  $options field options
     * @return string          field input
     */
    public function renderInput($table, $field, $data = '', array $options = [])
    {
        $relatedProperties = $this->_getRelatedProperties($options['fieldDefinitions']->getLimit(), $data);

        // Related module icon
        $icon = Configure::read('CsvMigrations.default_icon');
        if (!empty($relatedProperties['config']['table']['icon'])) {
            $icon = $relatedProperties['config']['table']['icon'];
        }

        // Help
        $help = '';
        $typeaheadFields = '';
        if (!empty($relatedProperties['config']['table']['typeahead_fields'])) {
            $typeaheadFields = explode(',', $relatedProperties['config']['table']['typeahead_fields']);
            if (empty(!$typeaheadFields)) {
                $typeaheadFields = implode(', or ', array_map(function ($value) {
                    return Inflector::humanize($value);
                }, $typeaheadFields));
            }
        }
        if (empty($typeaheadFields)) {
            $typeaheadFields = Inflector::humanize($relatedProperties['displayField']);
        }
        $help = $typeaheadFields;

        if (!empty($relatedProperties['dispFieldVal']) && !empty($relatedProperties['config']['parent']['module'])) {
            $relatedParentProperties = $this->_getRelatedParentProperties($relatedProperties);
            if (!empty($relatedParentProperties['dispFieldVal'])) {
                $relatedProperties['dispFieldVal'] = implode(' ' . $this->_separator . ' ', [
                    $relatedParentProperties['dispFieldVal'],
                    $relatedProperties['dispFieldVal']
                ]);
            }
        }

        $fieldName = $this->_getFieldName($table, $field, $options);

        $input = '';
        $input .= '<div class="form-group' . ((bool)$options['fieldDefinitions']->getRequired() ? ' required' : '') . '">';
        $input .= $this->cakeView->Form->label($field);
        $input .= '<div class="input-group">';
        $input .= '<span class="input-group-addon" title="' . $relatedProperties['controller'] . '"><span class="fa fa-' . $icon . '"></span></span>';

        $selectOptions = [
            $data => $relatedProperties['dispFieldVal']
        ];
        $input .= $this->cakeView->Form->input($fieldName, [
            'options' => $selectOptions,
            'label' => false,
            'id' => $field,
            'type' => 'select',
            'title' => $help,
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
            ])
        ]);

        if (!empty($options['embModal'])) {
            $input .= '<div class="input-group-btn">';
            $input .= '<button type="button" class="btn btn-default" data-toggle="modal" data-target="#' . $field . '_modal">';
            $input .= '<span class="glyphicon glyphicon-plus" aria-hidden="true"></span>';
            $input .= '</button>';
            $input .= '</div>';
        }
        $input .= '</div>';
        $input .= '</div>';

        return $input;
    }

    /**
     * Method that renders related field's value.
     *
     * @param  mixed  $table   name or instance of the Table
     * @param  string $field   field name
     * @param  string $data    field data
     * @param  array  $options field options
     * @return string
     */
    public function renderValue($table, $field, $data, array $options = [])
    {
        $result = null;

        if (empty($data)) {
            return $result;
        }

        $relatedProperties[] = $this->_getRelatedProperties($options['fieldDefinitions']->getLimit(), $data);

        if (!empty($relatedProperties[0]['config']['parent']['module'])) {
            array_unshift(
                $relatedProperties,
                $this->_getRelatedParentProperties($relatedProperties[0])
            );
        }

        $inputs = [];
        foreach ($relatedProperties as $properties) {
            if (empty($properties)) {
                continue;
            }
            if (isset($options['renderAs']) && $options['renderAs'] === static::RENDER_PLAIN_VALUE) {
                $inputs[] = h($properties['dispFieldVal']);
            } else {
                // generate related record(s) html link
                $inputs[] = $this->cakeView->Html->link(
                    h($properties['dispFieldVal']),
                    $this->cakeView->Url->build([
                        'prefix' => false,
                        'plugin' => $properties['plugin'],
                        'controller' => $properties['controller'],
                        'action' => static::LINK_ACTION,
                        $properties['id']
                    ]),
                    ['class' => 'label label-primary']
                );
            }
        }

        if (!empty($inputs)) {
            $result .= implode(' ' . $this->_separator . ' ', $inputs);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function renderSearchInput($table, $field, array $options = [])
    {
        $relatedProperties = $this->_getRelatedProperties($options['fieldDefinitions']->getLimit(), null);

        // Related module icon
        $icon = Configure::read('CsvMigrations.default_icon');
        if (!empty($relatedProperties['config']['table']['icon'])) {
            $icon = $relatedProperties['config']['table']['icon'];
        }

        // Help
        $help = '';
        $typeaheadFields = '';
        if (!empty($relatedProperties['config']['table']['typeahead_fields'])) {
            $typeaheadFields = explode(',', $relatedProperties['config']['table']['typeahead_fields']);
            if (empty(!$typeaheadFields)) {
                $typeaheadFields = implode(', or ', array_map(function ($value) {
                    return Inflector::humanize($value);
                }, $typeaheadFields));
            }
        }
        if (empty($typeaheadFields)) {
            $typeaheadFields = Inflector::humanize($relatedProperties['displayField']);
        }
        $help = $typeaheadFields;

        $content = sprintf(
            static::HTML_SEARCH_INPUT,
            $relatedProperties['controller'],
            $icon,
            $this->cakeView->Form->input($field, [
                'label' => false,
                'options' => ['{{value}}' => ''],
                'name' => '{{name}}',
                'id' => $field,
                'type' => 'select',
                'title' => $help,
                'data-type' => 'select2',
                'data-display-field' => $relatedProperties['displayField'],
                'escape' => false,
                'autocomplete' => 'off',
                'data-url' => $this->cakeView->Url->build([
                    'prefix' => 'api',
                    'plugin' => $relatedProperties['plugin'],
                    'controller' => $relatedProperties['controller'],
                    'action' => 'lookup.json'
                ])
            ])
        );

        return [
            'content' => $content,
            'post' => [
                [
                    'type' => 'script',
                    'content' => 'CsvMigrations.select2.full.min',
                    'block' => 'scriptBottom'
                ],
                [
                    'type' => 'script',
                    'content' => 'CsvMigrations.select2',
                    'block' => 'scriptBottom'
                ],
                [
                    'type' => 'scriptBlock',
                    'content' => 'csv_migrations_select2.setup(' . json_encode(
                        array_merge(
                            Configure::read('CsvMigrations.select2'),
                            Configure::read('CsvMigrations.api')
                        )
                    ) . ');',
                    'block' => 'scriptBottom'
                ],
                [
                    'type' => 'css',
                    'content' => 'CsvMigrations.select2.min',
                    'block' => 'cssBottom'
                ],
                [
                    'type' => 'css',
                    'content' => 'CsvMigrations.select2-bootstrap.min',
                    'block' => 'cssBottom'
                ],
            ]
        ];
    }

    /**
     * Method responsible for converting csv field instance to database field instance.
     *
     * @param  \CsvMigrations\FieldHandlers\CsvField $csvField CsvField instance
     * @return array list of DbField instances
     */
    public function fieldToDb(CsvField $csvField)
    {
        $dbFields[] = new DbField(
            $csvField->getName(),
            static::DB_FIELD_TYPE,
            null,
            $csvField->getRequired(),
            $csvField->getNonSearchable(),
            $csvField->getUnique()
        );

        return $dbFields;
    }
}
