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
     * Loaded Table instances
     *
     * @var array
     */
    protected $_tableInstances = [];

    /**
     * View instance.
     *
     * @var \Cake\View\View
     */
    public $cakeView = null;

    /**
     * Constructor
     *
     * @param mixed $cakeView View object or null
     */
    public function __construct($cakeView = null)
    {
        $this->cakeView = $cakeView;
    }

    /**
     * Render field form input
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
        $handler = $this->_getHandler($table, $field);

        return $handler->renderInput($data, $options);
    }

    /**
     * Render field search form input
     *
     * @param  mixed  $table   name or instance of the Table
     * @param  string $field   field name
     * @return string          field input
     */
    public function renderSearchInput($table, $field)
    {
        $table = $this->_getTableInstance($table);
        $handler = $this->_getHandler($table, $field);

        return $handler->renderSearchInput($options);
    }

    /**
     * Get search operators
     *
     * @param mixed $table Name or instance of the Table
     * @param string $field Field name
     * @return array
     */
    public function getSearchOperators($table, $field)
    {
        $table = $this->_getTableInstance($table);
        $handler = $this->_getHandler($table, $field);

        return $handler->getSearchOperators();
    }

    /**
     * Get field search field label
     *
     * @param mixed $table Name or instance of the Table
     * @param string $field Field name
     * @return string
     */
    public function getSearchLabel($table, $field)
    {
        $table = $this->_getTableInstance($table);
        $handler = $this->_getHandler($table, $field);

        return $handler->getSearchLabel();
    }

    /**
     * Render field value
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
        $handler = $this->_getHandler($table, $field);

        return $handler->renderValue($data, $options);
    }

    /**
     * Convert field CSV into database fields
     *
     * @todo Figure out which one of the two fields we actually need
     * @param  \CsvMigrations\FieldHandlers\CsvField $csvField CsvField instance
     * @return array list of DbField instances
     */
    public function fieldToDb(CsvField $csvField, $table, $field)
    {
        $handler = $this->_getHandler($table, $field);
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
        $interface = __NAMESPACE__ . '\\' . static::FIELD_HANDLER_INTERFACE;

        $handlerName = $this->_getHandlerClassName($fieldType, true);
        if (class_exists($handlerName) && in_array($interface, class_implements($handlerName))) {
            return true;
        }

        return false;
    }

    /**
     * Get table instance
     *
     * @throws \InvalidArgumentException when $table is not an object or string
     * @param  mixed  $table  name or instance of the Table
     * @return object         Table instance
     */
    protected function _getTableInstance($table)
    {
        $tableName = '';
        if (is_object($table)) {
            $tableName = $table->alias();
            // Update instance cache with the freshest copy and exist
            $this->_tableInstances[$tableName] = $table;

            return $table;
        } elseif (is_string($table)) {
            // Will need to do some work later
            $tableName = $table;
        } else {
            // Avoid ambiguity
            throw new \InvalidArgumentException("Table must be a name or instance object");
        }

        // Return a cached instance if we have one
        if (in_array($tableName, array_keys($this->_tableInstances))) {
            return $this->_tableInstances[$tableName];
        }

        // Populate cache
        $this->_tableInstances[$tableName] = TableRegistry::get($tableName);

        return $this->_tableInstances[$tableName];
    }

    /**
     * Get field handler instance
     *
     * This method returns an instance of the appropriate
     * FieldHandler class.
     *
     * @throws \RuntimeException when failed to instantiate field handler
     * @param  Table         $table Table instance
     * @param  string|array  $field Field name
     * @return object               FieldHandler instance
     */
    protected function _getHandler($table, $field)
    {
        if (!is_object($table)) {
            throw new \InvalidArgumentException("Table parameter is not an instance of Table class");
        }
        if (empty($field)) {
            throw new \InvalidArgumentException("Field parameter is empty");
        }

        // Prepare the stub field
        $fieldName = '';
        $stubField = [];
        if (is_string($field)) {
            $fieldName = $field;
            $stubField = [
                $fieldName => [
                    'name' => $fieldName,
                    'type' => 'string',
                ],
            ];
        } elseif (is_array($field)) {
            if (empty($field['name'])) {
                throw new \InvalidArgumentException("Field array is missing 'name' key");
            }
            if (empty($field['type'])) {
                throw new \InvalidArgumentException("Field array is missing 'type' key");
            }
            $fieldName = $field['name'];
            $stubField = [
                $fieldName => $field,
            ];
        } else {
            throw new \InvalidArgumentException("Field can be either a string or an associative array");
        }

        $fieldDefinitions = [];
        if (method_exists($table, 'getFieldsDefinitions') && is_callable([$table, 'getFieldsDefinitions'])) {
            $fieldDefinitions = $table->getFieldsDefinitions($stubField);
        } else {
            throw new \RuntimeException("Failed to get field definitions");
        }

        if (empty($fieldDefinitions[$fieldName])) {
            throw new \RuntimeException("Failed to get definition for field '$fieldName'");
        }

        $field = new CsvField($fieldDefinitions[$fieldName]);
        $fieldType = $field->getType();

        $interface = __NAMESPACE__ . '\\' . static::FIELD_HANDLER_INTERFACE;

        $handlerName = $this->_getHandlerClassName($fieldType, true);
        if (class_exists($handlerName) && in_array($interface, class_implements($handlerName))) {
            return new $handlerName($table, $fieldName, $this->cakeView);
        }

        throw new \RuntimeException("No field handler defined for field type [$fieldType]");
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
    protected function _getHandlerClassName($type, $withNamespace = false)
    {
        $result = Inflector::camelize($type) . static::HANDLER_SUFFIX;

        if ($withNamespace) {
            $result = __NAMESPACE__ . '\\' . $result;
        }

        return $result;
    }
}
