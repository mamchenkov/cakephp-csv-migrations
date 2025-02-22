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
deprecationWarning('"CsvMigrations.View/view" view is deprecated.');

use Cake\Utility\Inflector;


$options = [
    'entity' => $entity,
    'fields' => $fields,
    'title' => null
];
echo $this->element('CsvMigrations.View/view', [
    'options' => $options
]);
