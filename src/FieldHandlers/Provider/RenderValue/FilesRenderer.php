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
namespace CsvMigrations\FieldHandlers\Provider\RenderValue;

use CsvMigrations\Utility\FileUpload;

/**
 * FilesRenderer
 *
 * Files value as a linkable URL with icon
 */
class FilesRenderer extends AbstractRenderer
{
    /**
     * Provide rendered value
     *
     * @param mixed $data Data to use for provision
     * @param array $options Options to use for provision
     * @return mixed
     */
    public function provide($data = null, array $options = [])
    {
        $result = (string)$data;

        if (empty($result)) {
            return $result;
        }

        $table = $this->config->getTable();
        $field = $this->config->getField();

        $fileUpload = new FileUpload($table);

        $entities = $fileUpload->getFiles($table, $field, $data);

        $params = [
            'entities' => $entities
        ];

        $defaultElement = 'CsvMigrations.FieldHandlers/FilesFieldHandler/value';
        $element = empty($options['element']) ? $defaultElement : $options['element'];

        return $this->renderElement($element, $params);
    }
}
