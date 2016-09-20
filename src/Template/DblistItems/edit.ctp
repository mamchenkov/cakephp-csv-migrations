<?php
$this->extend('/Common/panel-wrapper');
$addUrl = [
    'plugin' => $this->request->plugin,
    'controller' => $this->request->controller,
    'action' => 'add',
    $list->get('id')
];
$mainTitle = $this->element(
    'top-row',
    [
        'link' => $addUrl,
        'showOptions' => ['back' => true],
        'title' => __d('CsvMigrations', 'Edit Dblist Item of {0}', $list->get('name')),
    ]
);
$this->assign('main-title', $mainTitle);
$this->assign('panel-title', __d('CsvMigrations', 'Details'));
?>
<?= $this->Form->create($dblistItem); ?>
<div class="row">
    <div class="col-xs-12">
        <fieldset>
        <div class="row">
            <div class="col-xs-6">
                <?= $this->Form->input('parent_id', ['options' => $tree, 'escape' => false, 'empty' => true]); ?>
            </div>
        </div>
        <div class="row">
            <div class="col-xs-6">
                <?= $this->Form->input('name'); ?>
            </div>
            <div class="col-xs-6">
                <?= $this->Form->input('value'); ?>
            </div>
        </div>
        <div class="row">
            <div class="col-xs-6">
                <?= $this->Form->input('active'); ?>
            </div>
        </div>
        </fieldset>
        <?= $this->Form->hidden('dblist_id',['value' => $list['id']]); ?>
        <?= $this->Form->button(__d('CsvMigrations', "Submit"), ['class' => 'btn btn-primary']); ?>
        <?= $this->Form->end() ?>
    </div>
</div>