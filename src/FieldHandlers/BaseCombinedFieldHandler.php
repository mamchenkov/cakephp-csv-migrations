<?php
namespace CsvMigrations\FieldHandlers;

use CsvMigrations\FieldHandlers\ListFieldHandler;

abstract class BaseCombinedFieldHandler extends ListFieldHandler
{
    /**
     * Input(s) wrapper html markup
     */
    const WRAPPER_HTML = '%s<div class="row">%s</div>';

    /**
     * Input field html markup
     */
    const INPUT_HTML = '<div class="col-xs-6 col-lg-4">%s</div>';

    /**
     * Combined fields
     *
     * @var array
     */
    protected $_fields = [];

    /**
     * {@inheritDoc}
     */
    public function __construct($table, $field, $cakeView = null)
    {
        parent::__construct($table, $field, $cakeView);

        $this->_setCombinedFields();
    }

    /**
     * Set combined fields
     *
     * @return void
     */
    abstract protected function _setCombinedFields();

    /**
     * {@inheritDoc}
     *
     * @todo refactor to use base fields as renderValue() does now
     */
    public function renderInput($data = '', array $options = [])
    {
        $label = $this->cakeView->Form->label($this->field);

        $inputs = [];
        foreach ($this->_fields as $suffix => $preOptions) {
            $options['fieldDefinitions']->setType($preOptions['handler']::DB_FIELD_TYPE);
            $options['label'] = null;
            $fieldName = $this->field . '_' . $suffix;

            $fieldData = $this->_getFieldValueFromData($data, $fieldName);
            if (empty($fieldData) && !empty($options['entity'])) {
                $fieldData = $this->_getFieldValueFromData($options['entity'], $fieldName);
            }

            $handler = new $preOptions['handler']($this->table, $fieldName, $this->cakeView);

            $inputs[] = sprintf(static::INPUT_HTML, $handler->renderInput($fieldData, $options));
        }

        return sprintf(static::WRAPPER_HTML, $label, implode('', $inputs));
    }

    /**
     * {@inheritDoc}
     */
    public function renderValue($data, array $options = [])
    {
        $result = [];
        foreach ($this->_fields as $suffix => $fieldOptions) {
            $fieldName = $this->field . '_' . $suffix;
            $fieldData = $this->_getFieldValueFromData($data, $fieldName);
            // fieldData will most probably be empty when dealing with combined fields for
            // example, field 'salary' will have no data since is converted to 'salary_amount'
            // and 'salary_currency'. In these cases we just re-call _getFeildValueFromData
            // method and we pass to it the whole entity.
            if (empty($fieldData) && !empty($options['entity'])) {
                $fieldData = $this->_getFieldValueFromData($options['entity'], $fieldName);
            }
            $handler = new $fieldOptions['handler']($this->table, $fieldName, $this->cakeView);
            $result[] = $handler->renderValue($fieldData, $options);
        }

        $result = implode('&nbsp;', $result);

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function renderSearchInput(array $options = [])
    {
        $result = [];
        foreach ($this->_fields as $suffix => $fieldOptions) {
            $options['fieldDefinitions']->setType($fieldOptions['handler']::DB_FIELD_TYPE);
            $fieldName = $this->field . '_' . $suffix;
            $handler = new $fieldOptions['handler']($this->table, $fieldName, $this->cakeView);
            $result[$fieldName] = $handler->renderSearchInput($options);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function fieldToDb(CsvField $csvField)
    {
        $dbFields = [];
        foreach ($this->_fields as $suffix => $options) {
            $subField = clone $csvField;
            $subField->setName($csvField->getName() . '_' . $suffix);
            $handler = new $options['handler']($this->table, $subField->getName(), $this->cakeView);
            if (isset($options['limit'])) {
                $subField->setLimit($options['limit']);
            }

            $dbFields = array_merge($dbFields, $handler->fieldToDb($subField));
        }

        return $dbFields;
    }

    /**
     * {@inheritDoc}
     */
    public function getSearchOperators($type)
    {
        $result = [];
        foreach ($this->_fields as $suffix => $options) {
            $fieldName = $this->field . '_' . $suffix;
            $handler = new $options['handler']($this->table, $fieldName, $this->cakeView);

            $result[$fieldName] = $handler->getSearchOperators($this->_getFieldTypeByFieldHandler($handler));
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getSearchLabel()
    {
        $result = [];
        foreach ($this->_fields as $suffix => $options) {
            $fieldName = $this->field . '_' . $suffix;

            $result[$fieldName] = parent::getSearchLabel($fieldName);
        }

        return $result;
    }
}
