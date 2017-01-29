<?php
namespace CsvMigrations\FieldHandlers;

use CsvMigrations\FieldHandlers\BaseStringFieldHandler;

class UrlFieldHandler extends BaseStringFieldHandler
{
    /**
     * HTML form field type
     */
    const INPUT_FIELD_TYPE = 'url';

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

        // Only link to URLs with schema, to avoid unpredictable behavior
        if (!empty($result) && filter_var($result, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED)) {
            if (!isset($options['renderAs']) || !$options['renderAs'] === static::RENDER_PLAIN_VALUE) {
                $result = $this->cakeView->Html->link($result, $result, ['target' => '_blank']);
            }
        }

        return $result;
    }

    /**
     * Sanitize field value
     *
     * This method filters the value and removes anything
     * potentially dangerous.  Ideally, it should always be
     * called before rendering the value to the user, in
     * order to avoid cross-site scripting (XSS) attacks.
     *
     * @throws \InvalidArgumentException when data is not a string
     * @param  string $data    Field data
     * @param  array  $options Field options
     * @return string          Field value
     */
    public function sanitizeValue($data, array $options = [])
    {
        $data = parent::sanitizeValue($data, $options);
        $data = filter_var($data, FILTER_SANITIZE_URL);

        return $data;
    }
}
