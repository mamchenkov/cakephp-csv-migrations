<?php
use Cake\Event\Event;

foreach ($entities as $entity) {
    $menu = [];

    $url = [
        'prefix' => false,
        'plugin' => $plugin,
        'controller' => $controller,
        'action' => 'view',
        $entity->id
    ];
    $menu[] = [
        'html' => $this->Html->link('<i class="fa fa-eye"></i>', $url, [
            'title' => __('View'), 'class' => 'btn btn-default', 'escape' => false
        ]),
        'url' => $url,
        'icon' => 'eye',
        'label' => __('View'),
        'noLabel' => true,
        'class' => 'btn btn-default',
    ];

    $url = [
        'prefix' => false,
        'plugin' => $plugin,
        'controller' => $controller,
        'action' => 'edit',
        $entity->id
    ];
    $menu[] = [
        'html' => $this->Html->link('<i class="fa fa-pencil"></i>', $url, [
            'title' => __('Edit'), 'class' => 'btn btn-default', 'escape' => false
        ]),
        'url' => $url,
        'icon' => 'pencil',
        'label' => __('Edit'),
        'noLabel' => true,
        'class' => 'btn btn-default',
    ];

    $url = [
        'prefix' => 'api',
        'plugin' => $plugin,
        'controller' => $controller,
        'action' => 'delete',
        '_ext' => 'json',
        $entity->id
    ];
    $menu[] = [
        'html' => $this->Html->link('<i class="fa fa-trash"></i>', $url, [
            'title' => __('Delete'),
            'class' => 'btn btn-default',
            'escape' => false,
            'data-type' => 'ajax-delete-record',
            'data-confirm-msg' => __(
                'Are you sure you want to delete {0}?',
                $entity->has($displayField) && !empty($entity->{$displayField}) ?
                    strip_tags($entity->{$displayField}) :
                    'this record'
            )
        ]),
        'url' => $url,
        'icon' => 'trash',
        'label' => __('Delete'),
        'noLabel' => true,
        'dataType' => 'ajax-delete-record',
        'class' => 'btn btn-default',
        'confirmMsg' => __(
            'Are you sure you want to delete {0}?',
            $entity->has($displayField) && !empty($entity->{$displayField}) ?
                    strip_tags($entity->{$displayField}) :
                    'this record'
        )

    ];

    // broadcast menu event
    $event = new Event('CsvMigrations.Index.actionsMenu.beforeRender', $this, [
        'menu' => $menu,
        'user' => $user,
        'type' => 'actions',
    ]);
    $this->EventManager()->dispatch($event);

    $result = '<div class="btn-group btn-group-xs" role="group">' . $event->result . '</div>';
    $entity->{$propertyName} = $result;
}
