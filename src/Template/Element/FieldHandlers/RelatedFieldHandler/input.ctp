<div class="form-group<?= $required ? ' required' : '' ?>">
    <?= $this->Form->label($name, $label, ['class' => 'control-label']) ?>
    <div class="input-group select2-bootstrap-prepend select2-bootstrap-append">
        <span class="input-group-addon" title="<?= $relatedProperties['controller'] ?>">
            <span class="fa fa-<?= $icon ?>"></span>
        </span>
        <?= $this->Form->input($name, [
            'options' => [$value => $relatedProperties['dispFieldVal']],
            'label' => false,
            'id' => $field,
            'type' => $type,
            'title' => $title,
            'data-type' => 'select2',
            'data-display-field' => $relatedProperties['displayField'],
            'escape' => false,
            'autocomplete' => 'off',
            'required' => (bool)$required,
            'data-url' => $this->Url->build([
                'prefix' => 'api',
                'plugin' => $relatedProperties['plugin'],
                'controller' => $relatedProperties['controller'],
                'action' => 'lookup.json'
            ])
        ]);
        ?>
        <?php if ($embedded) : ?>
            <div class="input-group-btn">
                <button type="button" class="btn btn-default" data-toggle="modal" data-target="#<?= $field ?>_modal">
                    <i class="fa fa-plus" aria-hidden="true"></i>
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>