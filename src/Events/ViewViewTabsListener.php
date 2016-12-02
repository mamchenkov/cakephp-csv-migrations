<?php
namespace CsvMigrations\Events;

use Cake\Datasource\ConnectionManager;
use Cake\Event\Event;
use Cake\Event\EventListenerInterface;
use Cake\ORM\Association;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use CsvMigrations\MigrationTrait;
use CsvMigrations\Panel;
use CsvMigrations\PanelUtilTrait;
use CsvMigrations\Parser\Csv\ViewParser;
use CsvMigrations\PathFinder\ViewPathFinder;

class ViewViewTabsListener implements EventListenerInterface
{
    use MigrationTrait;

    const ASSOC_FIELDS_ACTION = 'index';

    protected $_tableInstance;
    protected $_controllerInstance;
    protected $_assocTypes;

    /**
     * Implemented Events
     * @return array
     */
    public function implementedEvents()
    {
        return [
            'CsvMigrations.View.View.Tabs' => 'getViewTabs',
            'CsvMigrations.View.View.TabsList' => 'getTabsList',
            //'CsvMigrations.View.View.TabContent.beforeContent' => 'getBeforeTabContent',
            'CsvMigrations.View.View.TabContent' => 'getTabContent',
            'CsvMigrations.View.View.TabContent.afterContent' => 'getAfterTabContent',
        ];
    }

    /**
     * getBeforeTabContent
     * @param Cake\Event $event passed
     * @param array $data - containing tab content
     * @return void
     */
    public function getBeforeTabContent(Event $event, $request, $entity, $options)
    {
        $result = [
          'title' => __('beforeTab Title'),
          'content' => [
            'records' => [],
            'length' => 0,
          ],
          'options' => [
            'displayTemplate' => 'panel_table',
            'order' => 'beforeTabContent',
            ],
        ];

        return $result;
    }

    /**
     * getAfterTabContent
     * @param Cake\Event $event passed
     * @param array $data containing tab content
     * @return void
     */
    public function getAfterTabContent(Event $event, array $data)
    {
        return null;
    }

    /**
     * getTabsList method
     * Return the list of associations for the Entity
     * as the tabs
     * @param Cake\Event $event passed
     * @param Cake\Request $request from the view
     * @param Cake\ORM\Entity $entity passed
     * @param array $options extra setup
     * @return array $tabs list with its labels and classes
     */
    public function getTabsList(Event $event, $request, $entity, $options)
    {
        $tabs = [];
        $labels = [];
        $params = $request->params;
        $table = $params['controller'];
        if (!is_null($params['plugin'])) {
            $table = $params['plugin'] . '.' . $table;
        }

        $this->_tableInstance = TableRegistry::get($table);

        $config = $this->_tableInstance->getConfig();
        $hiddenAssociations = $this->_tableInstance->hiddenAssociations();

        if (!empty($config['associationLabels'])) {
            $labels = $this->_tableInstance->associationLabels($config['associationLabels']);
        }

        foreach ($this->_tableInstance->associations() as $association) {
            if (in_array($association->name(), $hiddenAssociations)) {
                continue;
            }

            list($namespace, $class) = namespaceSplit(get_class($association));

            $tab = [
                'label' => $association->alias(),
                'alias' => $association->alias(),
                'table' => $association->table(),
                'containerId' => Inflector::underscore($association->alias()),
                'associationName' => $association->name(),
                'associationType' => $association->type(),
                'associationObject' => $class,
                'targetClass' => $association->className(),
            ];

            // no need to show BelongsTo tabs.
            // The relationship is shown via the field in the main view.
            if (in_array($tab['associationObject'], ['BelongsTo'])) {
                continue;
            }

            if (in_array($association->alias(), array_keys($labels))) {
                $tab['label'] = $labels[$association->alias()];
            } else {
                $tab['label'] = Inflector::humanize($association->table());
                $fieldName = str_replace($tab['label'], '', Inflector::humanize(Inflector::tableize($association->alias())));
                if (!empty($fieldName)) {
                    $tab['label'] .= sprintf(" (%s)", $fieldName);
                }
            }

            if (!empty($tab['targetClass'])) {
                array_push($tabs, $tab);
            }
        }

        return compact('tabs');
    }

    /**
     * getTabContent method
     * @param Cake\Event $event passed from AppView
     * @param Cake\Request $request from the view
     * @param Cake\ORM\Entity $entity of the record
     * @param array $options for extra setup
     * @return array $content returned
     */
    public function getTabContent(Event $event, $request, $entity, $options)
    {
        $content = [];
        $params = $request->params;
        $table = $params['controller'];

        if (!is_null($params['plugin'])) {
            $table = $params['plugin'] . '.' . $table;
        }

        $this->_tableInstance = TableRegistry::get($table);

        $associationsMap = [
            'manyToMany' => '_manyToManyAssociatedRecords',
            'oneToMany' => '_oneToManyAssociatedRecords',
            'manyToOne' => '_manyToOneAssociatedRecords',
        ];

        foreach ($this->_tableInstance->associations() as $association) {
            if ($options['tab']['associationName'] == $association->name()) {
                $type = $association->type();

                if (in_array($type, array_keys($associationsMap))) {
                    $content = $this->{$associationsMap[$type]}($association);
                }
            }
        }

        return $content;
    }



    /**
     * getViewViewTabs method
     *
     * @param Cake\Event\Event $event used for getting reports
     * @param ArrayObject $options for params
     * @return array $data with all .ini reports
     */
    public function getViewTabs(Event $event, array $instances)
    {
        $this->_controllerInstance = $instances['controllerInstance'];
        $this->_tableInstance = $instances['tableInstance'];
        $this->_assocTypes = $instances['assocTypes'];
        $hiddenAssociations = [];
        $csvAssociationLabels = [];
        $csvAssociatedRecords = [];

        if (method_exists($this->_tableInstance, 'getConfig')) {
            $tableConfig = $this->_tableInstance->getConfig();

            $hiddenAssociations = $this->_tableInstance->hiddenAssociations();
            if (!empty($tableConfig['associationLabels'])) {
                $csvAssociationLabels = $this->_tableInstance->associationLabels($tableConfig['associationLabels']);
            }
        }

        foreach ($this->_tableInstance->associations() as $association) {
            if (in_array($association->name(), $hiddenAssociations)) {
                continue;
            }

            $assocType = $association->type();
            if (in_array($assocType, $this->_assocTypes)) {
                // get associated records
                switch ($assocType) {
                    case 'manyToOne':
                        $associatedRecords = $this->_manyToOneAssociatedRecords($association);
                        if (!empty($associatedRecords)) {
                            $csvAssociatedRecords[$assocType][$association->foreignKey()] = $associatedRecords;
                        }
                        break;

                    case 'oneToMany':
                        $associatedRecords = $this->_oneToManyAssociatedRecords($association);
                        if (!empty($associatedRecords)) {
                            $csvAssociatedRecords[$assocType][$association->name()] = $associatedRecords;
                        }
                        break;

                    case 'manyToMany':
                        $csvAssociatedRecords[$assocType][$association->name()] = $this->_manyToManyAssociatedRecords(
                            $association
                        );
                        break;
                }
            }
        }

        return compact('csvAssociatedRecords', 'csvAssociationLabels');
    }


    /**
     * Method that retrieves many to many associated records
     *
     * @param  \Cake\ORM\Association $association Association object
     * @return array                              associated records
     * @todo  find better way to fetch associated data, without including current table's data
     */
    protected function _manyToManyAssociatedRecords(Association $association)
    {
        $result = [];
        $assocName = $association->name();
        $assocTableName = $association->table();
        $assocForeignKey = $association->foreignKey();

        $csvFields = $this->_getAssociationCsvFields($association, static::ASSOC_FIELDS_ACTION);
        if (empty($csvFields)) {
            return $result;
        }
        // get associated index View csv fields
        $fields = array_unique(
            array_merge(
                [$association->displayField()],
                $csvFields
            )
        );
        $query = $this->_tableInstance->find('all', [
            'conditions' => [$this->_tableInstance->primaryKey() => $this->_controllerInstance->request->params['pass'][0]],
            'contain' => [
                $assocName
            ]
        ]);
        $records = $query->first()->{$assocTableName};
        // store association name
        $result['assoc_name'] = $assocName;
        // store associated table name
        $result['table_name'] = $assocTableName;
        // store associated table class name
        $result['class_name'] = $association->className();
        // store associated table display field
        $result['display_field'] = $association->displayField();
        // store associated table primary key
        $result['primary_key'] = $association->primaryKey();
        // store associated table foreign key
        $result['foreign_key'] = Inflector::singularize($assocTableName) . '_' . $association->primaryKey();
        // store associated table fields
        $result['fields'] = $fields;
        // store associated table records
        $result['records'] = $records;

        return $result;
    }

    /**
     * Method that retrieves many to one associated records.
     *
     * @param  \Cake\ORM\Association $association Association object
     * @return array                              associated records
     */
    protected function _manyToOneAssociatedRecords(Association $association)
    {
        $result = [];
        $tableName = $this->_tableInstance->table();
        $primaryKey = $this->_tableInstance->primaryKey();
        $assocTableName = $association->table();
        $assocPrimaryKey = $association->primaryKey();
        $assocForeignKey = $association->foreignKey();
        $recordId = $this->_controllerInstance->request->params['pass'][0];
        $displayField = $association->displayField();

        /*
         * skip inverse relationship
         *
         * @todo find better way to handle it
         */
        if ($tableName === $assocTableName) {
            return $result;
        }

        $connection = ConnectionManager::get('default');
        // NOTE: This will break if $assocTableName has no primary key or has a combined primary key
        $records = $connection
            ->execute(
                'SELECT ' . $assocTableName . '.' . $assocPrimaryKey . ' FROM ' . $tableName . ' LEFT JOIN ' . $assocTableName . ' ON ' . $tableName . '.' . $assocForeignKey . ' = ' . $assocTableName . '.' . $assocPrimaryKey . ' WHERE ' . $tableName . '.' . $primaryKey . ' = :id LIMIT 1',
                ['id' => $recordId]
            )
            ->fetchAll('assoc');

        // store associated table records, make sure associated record still exists.
        if (!empty($records[0][$assocPrimaryKey]) &&
            $association->exists([$assocPrimaryKey => $records[0][$assocPrimaryKey]])
        ) {
            //$result = $association->get($records[0][$assocPrimaryKey])->{$displayField};
            $records = $association->get($records[0][$assocPrimaryKey]);
        } else {
            $records = null;
        }

        try {
            $csvFields = $this->_getAssociationCsvFields($association, static::ASSOC_FIELDS_ACTION);
        } catch (\Exception $e) {
            $csvFields = [];
        }

        // get associated index View csv fields
        $fields = array_unique(
            array_merge(
                [$association->displayField()],
                $csvFields
            )
        );

        // store association name
        $result['assoc_name'] = $association->name();
        // store associated table name
        $result['table_name'] = $association->table();
        // store associated table class name
        $result['class_name'] = $association->className();
        // store associated table display field
        $result['display_field'] = $association->displayField();
        // store associated table primary key
        $result['primary_key'] = $association->primaryKey();
        // store associated table foreign key
        $result['foreign_key'] = Inflector::singularize($assocTableName) . '_' . $association->primaryKey();
        // store associated table fields
        $result['fields'] = $fields;
        // store associated table records
        $result['records'] = $records;

        return $result;
    }

    /**
     * Method that retrieves one to many associated records
     *
     * @param  \Cake\ORM\Association $association Association object
     * @return array                              associated records
     */
    protected function _oneToManyAssociatedRecords(Association $association)
    {
        $result = [];
        $assocName = $association->name();
        $assocTableName = $association->table();
        $assocForeignKey = $association->foreignKey();
        $recordId = $this->_controllerInstance->request->params['pass'][0];

        $csvFields = $this->_getAssociationCsvFields($association, static::ASSOC_FIELDS_ACTION);
        if (empty($csvFields)) {
            return $result;
        }

        // get associated index View csv fields
        $fields = array_unique(
            array_merge(
                [$association->displayField()],
                $csvFields
            )
        );

        $query = $this->_tableInstance->{$assocName}->find('all', [
            'conditions' => [$assocForeignKey => $recordId]
        ]);
        $records = $query->all();
        // store association name
        $result['assoc_name'] = $assocName;
        // store associated table name
        $result['table_name'] = $assocTableName;
        // store associated table class name
        $result['class_name'] = $association->className();
        // store associated table display field
        $result['display_field'] = $association->displayField();
        // store associated table primary key
        $result['primary_key'] = $association->primaryKey();
        // store associated table foreign key
        $result['foreign_key'] = $association->foreignKey();
        // store associated table fields
        $result['fields'] = $fields;
        // store associated table records
        $result['records'] = $records;

        return $result;
    }

    /**
     * Get association CSV fields
     * @param Cake\ORM\Associations $association ORM association
     * @param object $action action passed
     * @return array
     */
    protected function _getAssociationCsvFields(Association $association, $action)
    {
        list($plugin, $controller) = pluginSplit($association->className());
        $fields = $this->_getCsvFields($controller, $action);

        return $fields;
    }

    /**
     * Method that retrieves table csv fields, by specified action.
     *
     * @param  string $tableName Table name
     * @param  string $action    Action name
     * @return array             table fields
     */
    protected function _getCsvFields($tableName, $action)
    {
        $result = [];

        if (empty($tableName) || empty($action)) {
            return $result;
        }

        try {
            $pathFinder = new ViewPathFinder;
            $path = $pathFinder->find($tableName, $action);
            $csvFields = $this->_getFieldsFromCsv($path);
        } catch (Exception $e) {
            return $result;
        }

        if (empty($csvFields)) {
            return $result;
        }

        $result = array_map(function ($v) {
            return $v[0];
        }, $csvFields);

        return $result;
    }

    /**
     * Method that gets fields from a csv file
     *
     * @param  string $path   csv file path
     * @return array          csv data
     */
    protected function _getFieldsFromCsv($path)
    {
        $result = [];
        if (is_readable($path)) {
            $parser = new ViewParser();
            $result = $parser->parseFromPath($path);
        }

        return $result;
    }
}
