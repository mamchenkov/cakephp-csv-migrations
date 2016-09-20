<?php
$addUrl = [
    'plugin' => $this->request->plugin,
    'controller' => $this->request->controller,
    'action' => 'add',
    $list->get('id')
];
echo $this->element(
    'top-row',
    ['link' => $addUrl, 'title' => 'List items of ' . $list->get('name'), 'showOptions' => ['back' => true, 'add' => true]]
);
?>
<table class="table">
    <thead>
        <tr>
            <th><?= __d('CsvMigrations', 'Name'); ?></th>
            <th class="actions"><?= __d('CsvMigrations', 'Actions'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($tree as $entity): ?>
        <tr class="<?= !($entity->get('active')) ? 'warning' : ''; ?>">
            <td><?= $entity->get('spacer')?> (<?= $entity->get('value') ?>)</td>
            <td class="actions">
                <?= $this->Form->postLink('', ['action' => 'move_node', $entity->get('id'), 'up'], ['title' => __d('CsvMigrations', 'Move up'), 'class' => 'btn btn-default glyphicon glyphicon-arrow-up']) ?>
                <?= $this->Form->postLink('', ['action' => 'move_node', $entity->get('id'), 'down'], ['title' => __d('CsvMigrations', 'Move down'), 'class' => 'btn btn-default glyphicon glyphicon-arrow-down']) ?>
                <?= $this->Html->link('', ['action' => 'edit', $entity->get('id')], ['title' => __d('CsvMigrations', 'Edit'), 'class' => 'btn btn-default glyphicon glyphicon-pencil']) ?>
                <?= $this->Form->postLink('', ['action' => 'delete', $entity->get('id')], ['confirm' => __d('CsvMigrations', 'Are you sure you want to delete # {0}?', $entity->get('name')), 'title' => __d('CsvMigrations', 'Delete'), 'class' => 'btn btn-default glyphicon glyphicon-trash']) ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>