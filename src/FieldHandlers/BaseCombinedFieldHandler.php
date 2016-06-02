<?php
namespace CsvMigrations\FieldHandlers;

use App\View\AppView;
use CsvMigrations\FieldHandlers\ListFieldHandler;

abstract class BaseCombinedFieldHandler extends ListFieldHandler
{
    /**
     * Combined fields
     *
     * @var array
     */
    protected $_fields = [];

    public function __construct()
    {
        $this->_setCombinedFields();
    }

    /**
     * Set combined fields
     */
    abstract protected function _setCombinedFields();

    /**
     * {@inheritDoc}
     */
    public function renderInput($table, $field, $data = '', array $options = [])
    {
        $cakeView = new AppView();

        $input = $cakeView->Form->label($field . ' ' . key($this->_fields));

        $input .= '<div class="row">';
        foreach ($this->_fields as $suffix => $preOptions) {
            $fieldName = $field . '_' . $suffix;
            if (isset($options['entity'])) {
                $data = $options['entity']->{$fieldName};
            }
            $fullFieldName = $this->_getFieldName($table, $fieldName, $preOptions);

            $fieldOptions = [
                'label' => false,
                'type' => $preOptions['type'],
                'required' => (bool)$options['fieldDefinitions']['required'],
                'escape' => false,
                'value' => $data
            ];

            if (array_key_exists($fieldOptions['type'], $this->_fieldTypes)) {
                $fieldOptions['type'] = $this->_fieldTypes[$fieldOptions['type']];
            }

            $input .= '<div class="';
            switch ($preOptions['field']) {
                case 'select':
                    $input .= 'col-xs-6 col-sm-4 col-sm-offset-2">';
                    $selectOptions = $this->_getSelectOptions($options['fieldDefinitions']['type']);
                    $input .= $cakeView->Form->select($fullFieldName, $selectOptions, $fieldOptions);
                    break;

                case 'input':
                    $input .= 'col-xs-6">';
                    $input .= $cakeView->Form->input($fullFieldName, $fieldOptions);
                    break;
            }
            $input .= '</div>';
        }
        $input .= '</div>';

        return $input;
    }

    /**
     * {@inheritDoc}
     */
    public function renderValue($table, $field, $data, array $options = [])
    {
        $result = '';
        foreach ($this->_fields as $suffix => $preOptions) {
            $fieldName = $field . '_' . $suffix;
            if (isset($options['entity'])) {
                $data = $options['entity']->{$fieldName};
            }
            $fullFieldName = $this->_getFieldName($table, $fieldName, $preOptions);

            $fieldOptions = [
                'label' => false,
                'type' => $preOptions['type'],
                'required' => (bool)$options['fieldDefinitions']['required'],
                'value' => $data
            ];

            switch ($preOptions['field']) {
                case 'select':
                    $selectOptions = $this->_getSelectOptions($options['fieldDefinitions']['type']);
                    $result .= $selectOptions[$data];
                    break;

                case 'input':
                    $result = h($data) . ' ' . $result;
                    break;
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function fieldToDb(CsvField $csvField)
    {
        foreach ($this->_fields as $suffix => $options) {
            $dbFields[] = new DbField(
                $csvField->getName() . '_' . $suffix,
                $options['type'],
                null,
                $csvField->getRequired(),
                $csvField->getNonSearchable()
            );
        }

        return $dbFields;
    }
}
