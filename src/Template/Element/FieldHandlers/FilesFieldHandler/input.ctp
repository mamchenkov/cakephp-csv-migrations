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

$attributes = isset($attributes) ? $attributes : [];

$class = str_replace('.', '_', $name . '_ids');

$options = [
    'multiple' => true,
    'data-upload-url' => sprintf("/api/%s/upload", $table),
];
if ($value && $entities && $entities->count()) {
    $options['data-document-id'] = $value;
    $files = [];
    foreach ($entities as $entity) {
        $files[] = [
            'id' => $entity->id,
            'path' => $entity->path,
            'size' => $entity->get('filesize'),
        ];
    }
    //passed to generate previews
    $options['data-files'] = json_encode($files);
}
$attributes += $options;
?>
<div class="form-group <?= $required ? 'required' : '' ?> <?= $this->Form->isFieldError($name) ? 'has-error' : '' ?>">
<?= $this->Form->label($name . '[]', $label) ?>
<?= $this->Form->{$type}($name . '[]', $attributes); ?>
<?php if ($entities && $entities->count()) : ?>
    <?php foreach ($entities as $entity) : ?>
        <?= $this->Form->hidden(
            $name . '_ids[]',
            [
                'class' => $class,
                'value' => $entity->id
            ]
        ); ?>
    <?php endforeach; ?>
<?php else : ?>
    <?= $this->Form->hidden(
        $name . '_ids[]',
        [
            'class' => $class,
            'value' => ''
        ]
    ); ?>
<?php endif; ?>
<?php echo $this->Form->error($name) ?>
</div>
