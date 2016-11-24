<?php
namespace CsvMigrations\FieldHandlers;

use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use CsvMigrations\FieldHandlers\CsvField;
use CsvMigrations\ForeignKeysHandler;
use DirectoryIterator;
use RegexIterator;

class FieldHandlerFactory
{
    /**
     * Default Field Handler class name
     */
    const DEFAULT_HANDLER_CLASS = 'Default';

    /**
     * Field Handler classes suffix
     */
    const HANDLER_SUFFIX = 'FieldHandler';

    /**
     * Field Handler Interface class name
     */
    const FIELD_HANDLER_INTERFACE = 'FieldHandlerInterface';

    /**
     * Current Table name
     *
     * @var string
     */
    protected $_tableName;

    /**
     * Loaded Table instances
     *
     * @var array
     */
    protected $_tableInstances = [];

    /**
     * CsvMigrations View instance.
     *
     * @var \CsvMigrations\View\AppView
     */
    public $cakeView = null;

    /**
     * Constructor method.
     *
     * @param mixed $cakeView View object or null
     */
    public function __construct($cakeView = null)
    {
        $this->cakeView = $cakeView;
    }

    /**
     * Method responsible for rendering field's input.
     *
     * @param  mixed  $table   name or instance of the Table
     * @param  string $field   field name
     * @param  string $data    field data
     * @param  array  $options field options
     * @return string          field input
     */
    public function renderInput($table, $field, $data = '', array $options = [])
    {
        $table = $this->_getTableInstance($table);
        $options = $this->_getExtraOptions($table, $field, $options);
        $handler = $this->_getHandler($options['fieldDefinitions']->getType());

        return $handler->renderInput($table, $field, $data, $options);
    }

    /**
     * Method responsible for rendering field's search input.
     *
     * @param  mixed  $table   name or instance of the Table
     * @param  string $field   field name
     * @return string          field input
     */
    public function renderSearchInput($table, $field)
    {
        $table = $this->_getTableInstance($table);
        $options = $this->_getExtraOptions($table, $field);
        $handler = $this->_getHandler($options['fieldDefinitions']->getType());

        return $handler->renderSearchInput($table, $field, $options);
    }

    /**
     * Method that renders specified field's value based on the field's type.
     *
     * @param  mixed  $table   name or instance of the Table
     * @param  string $field   field name
     * @param  string $data    field data
     * @param  array  $options field options
     * @return string          list field value
     */
    public function renderValue($table, $field, $data, array $options = [])
    {
        $table = $this->_getTableInstance($table);
        $options = $this->_getExtraOptions($table, $field, $options);
        $handler = $this->_getHandler($options['fieldDefinitions']->getType());

        return $handler->renderValue($table, $field, $data, $options);
    }

    /**
     * Method responsible for converting csv field instance to database field instance.
     *
     * @param  \CsvMigrations\FieldHandlers\CsvField $csvField CsvField instance
     * @return array list of DbField instances
     */
    public function fieldToDb(CsvField $csvField)
    {
        $handler = $this->_getHandler($csvField->getType());
        $fields = $handler->fieldToDb($csvField);

        return $fields;
    }

    /**
     * Check if given field type has a field handler
     *
     * Previously, we used to load all available field handlers
     * via getList() method and check if the handler for the given
     * type was in that list.  However, this doesn't play well
     * with autoloaders.  It's better to rely on the autoloader
     * and namespaces, rather than on our search through directories.
     * Hence this check whether a particular handler exists.
     *
     * @param string $fieldType Field type
     * @return bool             True if yes, false otherwise
     */
    public function hasFieldHandler($fieldType)
    {
        $result = false;

        try {
            $handler = $this->_getHandler($fieldType, true);
            $result = true;
        } catch (\Exception $e) {
            $result = false;
        }

        return $result;
    }

    /**
     * Method that sets and returns Table instance
     *
     * @param  mixed  $table  name or instance of the Table
     * @return object         Table instance
     */
    protected function _getTableInstance($table)
    {
        // set table name
        if (is_object($table)) {
            $this->setTableName($table->alias());
        } else {
            $this->setTableName($table);
        }

        $tableInstance = $this->_setTableInstance($table);

        return $tableInstance;
    }

    /**
     * Method that adds extra parameters to the field options array.
     *
     * @param  object $tableInstance instance of the Table
     * @param  string $field         field name
     * @param  array  $options       field options
     * @return array
     */
    protected function _getExtraOptions($tableInstance, $field, array $options = [])
    {
        // get fields definitions
        $fieldsDefinitions = $tableInstance->getFieldsDefinitions($tableInstance->alias());

        /*
         * @todo make this better, probably define defaults (scenario virtual fields)
         */
        if (empty($options['fieldDefinitions']['type'])) {
            $options['fieldDefinitions']['type'] = 'string';
        }

        /*
        add field definitions to options array as CsvField Instance
         */
        if (!empty($fieldsDefinitions[$field])) {
            $options['fieldDefinitions'] = new CsvField($fieldsDefinitions[$field]);
        } else {
            $options['fieldDefinitions']['name'] = $field;
            $options['fieldDefinitions'] = new CsvField($options['fieldDefinitions']);
        }

        return $options;
    }

    /**
     * Get field handler instance
     *
     * This method returns an instance of the appropriate
     * FieldHandler class based on field Type.
     *
     * In case the field handler cannot be found or instantiated
     * the method either returns a default handler, or throws an
     * expcetion (based on $failOnError parameter).
     *
     * @throws \RuntimeException when failed to instantiate field handler and $failOnError is true
     * @param  string  $fieldType field type
     * @param  bool   $failOnError Whether or not to throw exception on failure
     * @return object            FieldHandler instance
     */
    protected function _getHandler($fieldType, $failOnError = false)
    {
        $interface = __NAMESPACE__ . '\\' . static::FIELD_HANDLER_INTERFACE;

        $handlerName = $this->_getHandlerByFieldType($fieldType, true);
        if (class_exists($handlerName) && in_array($interface, class_implements($handlerName))) {
            return new $handlerName($this->cakeView);
        }

        // Field hanlder does not exist, throw exception if necessary
        if ($failOnError) {
            throw new \RuntimeException("No field handler defined for field type [$fieldType]");
        }

        // Use default field handler
        $handlerName = __NAMESPACE__ . '\\' . static::DEFAULT_HANDLER_CLASS . static::HANDLER_SUFFIX;
        if (class_exists($handlerName) && in_array($interface, class_implements($handlerName))) {
            return new $handlerName($this->cakeView);
        }

        // Neither the handler, nor the default handler can be used
        throw new \RuntimeException("Default field handler [" . static::DEFAULT_HANDLER_CLASS . "] cannot be used");
    }

    /**
     * Set table name
     *
     * @param string $tableName table name
     * @return void
     */
    public function setTableName($tableName)
    {
        $this->_tableName = $tableName;
    }

    /**
     * Get field handler class name
     *
     * This method constructs handler class name based on provided field type.
     *
     * @param  string $type          field type
     * @param  bool   $withNamespace whether or not to include namespace
     * @return string                handler class name
     */
    protected function _getHandlerByFieldType($type, $withNamespace = false)
    {
        $result = Inflector::camelize($type) . static::HANDLER_SUFFIX;

        if ($withNamespace) {
            $result = __NAMESPACE__ . '\\' . $result;
        }

        return $result;
    }

    /**
     * Method that adds specified table to the _tableInstances
     * array and returns the table's instance.
     *
     * @param  mixed $table name or instance of the Table
     * @return object       instance of specified Table
     */
    protected function _setTableInstance($table)
    {
        // add table instance to _modelInstances array
        if (!in_array($this->_tableName, array_keys($this->_tableInstances))) {
            // get table instance
            if (!is_object($table)) {
                $table = TableRegistry::get($this->_tableName);
            }
            $this->_tableInstances[$this->_tableName] = $table;
        }

        return $this->_tableInstances[$this->_tableName];
    }
}
