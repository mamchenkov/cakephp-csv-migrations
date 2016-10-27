<?php
namespace CsvMigrations\Events;

use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Network\Request;
use Cake\ORM\Query;
use Cake\ORM\ResultSet;
use CsvMigrations\Events\BaseViewListener;

class IndexViewListener extends BaseViewListener
{
    /**
     * Include menus identifier
     */
    const FLAG_INCLUDE_MENUS = 'menus';

    /**
     * Property name for menu items
     */
    const MENU_PROPERTY_NAME = '_Menus';

    /**
     * Pretty format identifier
     */
    const FORMAT_PRETTY = 'pretty';

    /**
     * {@inheritDoc}
     */
    public function implementedEvents()
    {
        return [
            'CsvMigrations.Index.beforePaginate' => 'beforePaginate',
            'CsvMigrations.Index.afterPaginate' => 'afterPaginate',
            'CsvMigrations.Index.beforeRender' => 'beforeRender'
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function beforePaginate(Event $event, Query $query)
    {
        $query->contain($this->_getAssociations($event));
        $this->_filterByConditions($query, $event);
        $this->_selectActionFields($query, $event);
        $this->_handleDtSorting($query, $event);
    }

    /**
     * {@inheritDoc}
     */
    public function afterPaginate(Event $event, ResultSet $entities)
    {
        if ($entities->isEmpty()) {
            return;
        }

        // @todo temporary functionality, please see _includeFiles() method documentation.
        foreach ($entities as $entity) {
            $this->_includeFiles($entity, $event);
        }

        $this->_prettifyEntities($entities, $event);
        $this->_includeMenus($entities, $event);
    }

    /**
     * {@inheritDoc}
     */
    public function beforeRender(Event $event, ResultSet $entities)
    {
        if ($entities->isEmpty()) {
            return;
        }

        $this->_datatablesStructure($entities, $event);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getActionFields(Request $request, $action = null)
    {
        $fields = parent::_getActionFields($request, $action);

        if (empty($fields)) {
            return $fields;
        }

        foreach ($fields as &$field) {
            $field = current((array)$field);
        }

        return $fields;
    }

    /**
     * Method that adds SELECT clause to the Query to only include
     * action fields (as defined in the csv file).
     *
     * @param  \Cake\ORM\Query   $query Query object
     * @param  \Cake\Event\Event $event The event
     * @return void
     */
    protected function _selectActionFields(Query $query, Event $event)
    {
        if (!in_array($event->subject()->request->query('format'), [static::FORMAT_DATATABLES])) {
            return;
        }

        $fields = $this->_getActionFields($event->subject()->request);

        if (empty($fields)) {
            return;
        }

        $primaryKey = $event->subject()->{$event->subject()->name}->primaryKey();
        // always include primary key, useful for menus URLs
        if (!in_array($primaryKey, $fields)) {
            array_push($fields, $primaryKey);
        }

        $query->select($this->_databaseFields($fields, $event), true);
    }

    /**
     * Handle datatables sorting parameters to match Query order() accepted parameters.
     *
     * @param  \Cake\ORM\Query   $query Query object
     * @param  \Cake\Event\Event $event The event
     * @return void
     */
    protected function _handleDtSorting(Query $query, Event $event)
    {
        if (!in_array($event->subject()->request->query('format'), [static::FORMAT_DATATABLES])) {
            return;
        }

        if (!$event->subject()->request->query('order')) {
            return;
        }

        $sortCol = $event->subject()->request->query('order.0.column') ?: 0;
        $sortDir = $event->subject()->request->query('order.0.dir') ?: 'asc';
        if (!in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'asc';
        }

        $fields = $this->_getActionFields($event->subject()->request);
        if (empty($fields)) {
            return;
        }

        if (!isset($fields[$sortCol])) {
            return;
        }

        $query->order([$event->subject()->name . '.' . $fields[$sortCol] => $sortDir]);
    }

    /**
     * Method that prepares entities to run through pretiffy logic.
     *
     * @param  \Cake\ORM\ResultSet $entities Entities
     * @param  \Cake\Event\Event   $event    Event instance
     * @return void
     */
    protected function _prettifyEntities(ResultSet $entities, Event $event)
    {
        if (!in_array($event->subject()->request->query('format'), [static::FORMAT_PRETTY, static::FORMAT_DATATABLES])) {
            return;
        }

        $fields = [];
        if (static::FORMAT_DATATABLES === $event->subject()->request->query('format')) {
            $fields = $this->_getActionFields($event->subject()->request);
        }

        foreach ($entities as $entity) {
            $this->_prettify($entity, $event->subject()->{$event->subject()->name}, $fields);
        }
    }

    /**
     * Method that retrieves and attaches menu elements to API response.
     *
     * @param  \Cake\ORM\ResultSet $entities Entities
     * @param  \Cake\Event\Event   $event    Event instance
     * @return void
     */
    protected function _includeMenus(ResultSet $entities, Event $event)
    {
        if (!$event->subject()->request->query(static::FLAG_INCLUDE_MENUS)) {
            return;
        }

        foreach ($entities as $entity) {
            // broadcast menu event
            $ev = new Event('View.Index.Menu.Actions', $event->subject(), [
                'request' => $event->subject()->request,
                'options' => $entity
            ]);
            EventManager::instance()->dispatch($ev);

            $entity->{static::MENU_PROPERTY_NAME} = $ev->result;
        }
    }

    /**
     * Method that re-formats entities to Datatables supported format.
     *
     * @param  \Cake\ORM\ResultSet $entities Entities
     * @param  \Cake\Event\Event   $event    Event instance
     * @return void
     */
    protected function _datatablesStructure(ResultSet $entities, Event $event)
    {
        if (static::FORMAT_DATATABLES !== $event->subject()->request->query('format')) {
            return;
        }

        $fields = $this->_getActionFields($event->subject()->request);

        if (empty($fields)) {
            return;
        }

        $fields[] = static::MENU_PROPERTY_NAME;

        foreach ($entities as $entity) {
            // remove non-action fields property
            foreach (array_diff($entity->visibleProperties(), $fields) as $field) {
                $entity->unsetProperty($field);
            }

            // set fields with numberic index
            foreach ($fields as $k => $v) {
                $entity->{$k} = $entity->{$v};
                $entity->unsetProperty($v);
            }
        }
    }

    /**
     * Method that filters ORM records by provided conditions.
     *
     * @param  \Cake\ORM\Query   $query Query object
     * @param  \Cake\Event\Event $event The event
     * @return void
     */
    protected function _filterByConditions(Query $query, Event $event)
    {
        if (empty($event->subject()->request->query('conditions'))) {
            return;
        }

        $conditions = [];
        $tableName = $event->subject()->name;
        foreach ($event->subject()->request->query('conditions') as $k => $v) {
            if (false === strpos($k, '.')) {
                $k = $tableName . '.' . $k;
            }

            $conditions[$k] = $v;
        };

        $query->applyOptions(['conditions' => $conditions]);
    }
}
