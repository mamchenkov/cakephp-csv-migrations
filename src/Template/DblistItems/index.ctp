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

use CsvMigrations\FieldHandlers\FieldHandlerFactory;

$factory = new FieldHandlerFactory($this);
?>
<section class="content-header">
    <div class="row">
        <div class="col-xs-12 col-md-6">
        <h4>
            <?= __('Database List Items') ?>
            <small>
                <?= __('for') ?>
                <?= $this->Html->link($list->get('name'), ['controller' => 'Dblists', 'action' => 'index']) ?>
            </small>
        </h4>
        </div>
        <div class="col-xs-12 col-md-6">
            <div class="pull-right">
                <div class="btn-group btn-group-sm" role="group">
                    <?php
                    $url = ['plugin' => 'CsvMigrations', 'controller' => 'DblistItems', 'action' => 'add', $list->get('id')];
                    echo $this->Html->link('<i class="fa fa-plus"></i> ' . __('Add'), $url, [
                        'title' => __('Add'), 'escape' => false, 'class' => 'btn btn-default'
                    ]);
                    ?>
                </div>
            </div>
        </div>
    </div>
</section>
<section class="content">
    <div class="box box-primary">
        <div class="box-body table-responsive">
            <table class="table table-hover table-condensed table-vertical-align">
                <thead>
                    <tr>
                        <th><?= __('Name'); ?></th>
                        <th><?= __('Value'); ?></th>
                        <th><?= __('Created'); ?></th>
                        <th><?= __('Modified'); ?></th>
                        <th class="actions"><?= __('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($query as $entity) : ?>
                    <tr class="<?= $entity->get('active') ? '' : 'warning'; ?>">
                        <td><?= $entity->get('spacer')?></td>
                        <td><?= $entity->get('value') ?></td>
                        <td><?= $factory->renderValue('Dblists', 'created', $entity->get('created'), ['fieldDefinitions' => ['type' => 'datetime']]);?></td>
                        <td><?= $factory->renderValue('Dblists', 'modified', $entity->get('modified'), ['fieldDefinitions' => ['type' => 'datetime']]);?></td>
                        <td class="actions">
                            <div class="btn-group btn-group-xs" role="group">
                            <?php
                            echo $this->Form->postLink(
                                '<i class="fa fa-arrow-up"></i>',
                                [
                                    'plugin' => 'CsvMigrations',
                                    'controller' => 'DblistItems',
                                    'action' => 'move_node',
                                    $entity->get('id'),
                                    'up'
                                ],
                                ['title' => __('Move up'), 'class' => 'btn btn-default', 'escape' => false]
                            );

                            echo $this->Form->postLink(
                                '<i class="fa fa-arrow-down"></i>',
                                [
                                    'plugin' => 'CsvMigrations',
                                    'controller' => 'DblistItems',
                                    'action' => 'move_node',
                                    $entity->get('id'),
                                    'down'
                                ],
                                ['title' => __('Move down'), 'class' => 'btn btn-default', 'escape' => false]
                            );

                            echo $this->Html->link(
                                '<i class="fa fa-pencil"></i>',
                                [
                                    'plugin' => 'CsvMigrations',
                                    'controller' => 'DblistItems',
                                    'action' => 'edit',
                                    $entity->get('id')
                                ],
                                ['title' => __('Edit'), 'class' => 'btn btn-default', 'escape' => false]
                            );

                            echo $this->Form->postLink(
                                '<i class="fa fa-trash"></i>',
                                [
                                    'plugin' => 'CsvMigrations',
                                    'controller' => 'DblistItems',
                                    'action' => 'delete',
                                    $entity->get('id')
                                ],
                                [
                                    'title' => __('Delete'),
                                    'class' => 'btn btn-default',
                                    'escape' => false,
                                    'confirm' => __('Are you sure you want to delete {0}?', $entity->get('name'))
                                ]
                            );
                            ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
