<?php
/**
 * Copyright (c) Qobo Ltd. (https://www.qobo.biz)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Qobo Ltd. (https://www.qobo.biz)
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace CsvMigrations\FieldHandlers\Provider\RenderInput;

/**
 * PhoneRenderer
 *
 * Phone renderer provides the functionality
 * for rendering phone inputs.
 */
class PhoneRenderer extends AbstractRenderer
{
    /**
     * Provide
     *
     * @param mixed $data Data to use for provision
     * @param array $options Options to use for provision
     * @return mixed
     */
    public function provide($data = null, array $options = [])
    {
        $field = $this->config->getField();
        $table = $this->config->getTable();

        $fieldName = $table->aliasField($field);

        $attributes = empty($options['attributes']) ? [] : $options['attributes'];
        if (defined($this->config->getProvider('validationRules') . '::VALIDATION_PATTERN')) {
            $attributes['pattern'] = $this->config->getProvider('validationRules')::VALIDATION_PATTERN;
        }

        $params = [
            'field' => $field,
            'name' => $fieldName,
            'type' => 'tel',
            'label' => $options['label'],
            'required' => $options['fieldDefinitions']->getRequired(),
            'value' => $data,
            'extraClasses' => (!empty($options['extraClasses']) ? implode(' ', $options['extraClasses']) : ''),
            'attributes' => $attributes,
            'placeholder' => (!empty($options['placeholder']) ? $options['placeholder'] : ''),
        ];

        $defaultElement = 'CsvMigrations.FieldHandlers/BaseFieldHandler/input';
        $element = empty($options['element']) ? $defaultElement : $options['element'];

        return $this->renderElement($element, $params);
    }
}
