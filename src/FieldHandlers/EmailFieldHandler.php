<?php
namespace CsvMigrations\FieldHandlers;

use CsvMigrations\FieldHandlers\BaseStringFieldHandler;

class EmailFieldHandler extends BaseStringFieldHandler
{
    /**
     * HTML form field type
     */
    const INPUT_FIELD_TYPE = 'email';

    /**
     * Sanitize options
     *
     * Name of filter_var() filter to run and all desired
     * options/flags.
     *
     * @var array
     */
    public $sanitizeOptions = [FILTER_SANITIZE_EMAIL];

    /**
     * Render field value
     *
     * This method prepares the output of the value for the given
     * field.  The result can be controlled via the variety of
     * options.
     *
     * @param  string $data    Field data
     * @param  array  $options Field options
     * @return string          Field value
     */
    public function renderValue($data, array $options = [])
    {
        $options = array_merge($this->defaultOptions, $this->fixOptions($options));
        $data = (string)$this->_getFieldValueFromData($data);
        $result = $this->sanitizeValue($data, $options);

        // Only link to valid emails, to avoid unpredictable behavior
        if (!empty($result) && filter_var($result, FILTER_VALIDATE_EMAIL)) {
            if (!isset($options['renderAs']) || !$options['renderAs'] === static::RENDER_PLAIN_VALUE) {
                $result = $this->cakeView->Html->link($result, 'mailto:' . $result, ['target' => '_blank']);
            }
        }

        return $result;
    }
}
