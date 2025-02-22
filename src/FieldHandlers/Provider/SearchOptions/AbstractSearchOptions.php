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

namespace CsvMigrations\FieldHandlers\Provider\SearchOptions;

use CsvMigrations\FieldHandlers\Provider\AbstractProvider;

/**
 * AbstractSearchOptions
 *
 * Abstract base class extending AbstractProvider
 */
abstract class AbstractSearchOptions extends AbstractProvider
{
    /**
     * Helper method to get search operators
     *
     * @param mixed $data Data to use for provision
     * @param mixed[] $options Options to use for provision
     * @return mixed[]
     */
    protected function getSearchOperators($data = null, array $options = []): array
    {
        $result = $this->config->getProvider('searchOperators');
        $result = new $result($this->config);
        $result = $result->provide($data, $options);

        return $result;
    }

    /**
     * Get default search options
     *
     * @param mixed $data Data to use for provision
     * @param mixed[] $options Options to use for provision
     * @return mixed[]
     */
    protected function getDefaultOptions($data = null, array $options = []): array
    {
        $result = [
            'type' => $options['fieldDefinitions']->getType(),
            'label' => $options['label'],
            'operators' => $this->getSearchOperators($data, $options),
            'input' => [
                'content' => '',
            ],
        ];

        return $result;
    }

    /**
     * Get basic template for a given type
     *
     * @param string $type Form input type
     * @return string
     */
    protected function getBasicTemplate(string $type): string
    {
        $view = $this->config->getView();
        $result = $view->Form->control('{{name}}', [
            'value' => '{{value}}',
            'type' => $type,
            'label' => false
        ]);

        return $result;
    }
}
