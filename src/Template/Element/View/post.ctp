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

use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use CsvMigrations\CsvMigrationsUtils;

$defaultOptions = [
    'handlerOptions' => [],
    'title' => null,
    'entity' => null,
    'fields' => [],
];
if (empty($options)) {
    $options = [];
}
$options = array_merge($defaultOptions, $options);

$formOptions = [
    'url' => [
        'plugin' => $this->request->plugin,
        'controller' => $this->request->controller,
        'action' => $this->request->action
    ],
    'data-panels-url' => $this->Url->build([
        'prefix' => 'api',
        'plugin' => $this->request->plugin,
        'controller' => $this->request->controller,
        'action' => 'panels'
    ]),
    'name' => Inflector::dasherize($this->name),
    'type' => 'file',
    'templates' => [
        'inputContainerError' => '<div class="form-group input {{type}}{{required}} has-error">{{content}}{{error}}</div>',
        'error' => '<div class="error-message help-block">{{content}}</div>',
    ]
];

if (!empty($this->request->query['embedded'])) {
    $formOptions['url']['prefix'] = 'api';

    $embeddedTableName = $this->request->controller;
    if (!empty($this->request->plugin)) {
        $embeddedTableName = $this->request->plugin . '.' . $embeddedTableName;
    }
    $formOptions['data-embedded-display-field'] = TableRegistry::get($embeddedTableName)->displayField();
    $formOptions['data-embedded-field-id'] = $this->request->query['foreign_key'];
    $formOptions['data-embedded'] = true;
    $formOptions['data-embedded-association-name'] = $this->request->query['embedded'];
    if ($this->request->query('related_model') && $this->request->query('related_id')) {
        $formOptions['data-embedded-related-model'] = $this->request->query('related_model');
        $formOptions['data-embedded-related-id'] = $this->request->query('related_id');
    }
}
?>
<section class="content-header">
    <h4><?= $options['title'] ?></h4>
</section>
<section class="content">
    <?php
    /**
     * Conversion logic
     * @todo probably this has to be moved to another plugin
     */
    if (!$this->request->param('pass.conversion')) {
        echo $this->Form->create($options['entity'], $formOptions);
    }

    if (!empty($options['fields'])) {
        echo $this->element('CsvMigrations.Form/fields', ['options' => $options]);
    }

    /**
     * Conversion logic
     * @todo probably this has to be moved to another plugin
     */
    if (!$this->request->param('pass.conversion')) {
        echo $this->Form->button(__('Submit'), [
            'name' => 'btn_operation', 'value' => 'submit', 'class' => 'btn btn-primary'
        ]);

        echo $this->Html->link(__('Cancel'), ['action' => 'index'], array_merge(
            ['class' => 'btn btn-link', 'role' => 'button'],
            $this->request->getQuery('embedded') ? ['aria-label' => 'Close', 'data-dismiss' => 'modal'] : []
        ));
        echo $this->Form->end();

        // Fetch embedded module(s) using CakePHP's requestAction() method, if request is not coming from requestAction()
        echo $this->element('CsvMigrations.Form/fields_embedded', ['fields' => $options['fields']]);
    }
    ?>
</section>
<?php
/**
 * @todo  Load when needed.
 * - When there is file input
 * - load these files only if foreign/related field exists
 */
echo $this->element('CsvMigrations.common_js_libs');
