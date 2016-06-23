<?php
namespace CsvMigrations;

use Cake\ORM\Table as BaseTable;
use Cake\Utility\Inflector;
use CsvMigrations\ConfigurationTrait;
use CsvMigrations\FieldHandlers\CsvField;
use CsvMigrations\FieldTrait;
use CsvMigrations\ListTrait;
use CsvMigrations\MigrationTrait;

/**
 * Accounts Model
 *
 */
class Table extends BaseTable
{
    use ConfigurationTrait;
    use FieldTrait;

    use ListTrait, MigrationTrait
    {
     ListTrait::_prepareCsvData insteadof MigrationTrait;
     ListTrait::_getCsvData insteadof MigrationTrait;
    }

    /**
     * Searchable parameter name
     */
    const PARAM_NON_SEARCHABLE = 'non-searchable';

    /**
     * Initialize method
     *
     * @param array $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config)
    {
        parent::initialize($config);

        /*
        set table/module configuration
         */
        $this->_setConfiguration($this->table());

        /*
        display field from configuration file
         */
        if (isset($this->_config['table']['display_field'])) {
            $this->displayField($this->_config['table']['display_field']);
        }

        /*
        set module alias from configuration file
         */
        if (isset($this->_config['table']['alias'])) {
            $this->moduleAlias($this->_config['table']['alias']);
        }

        /*
        set searchable flag from configuration file
         */
        if (isset($this->_config['table']['searchable'])) {
            $this->isSearchable($this->_config['table']['searchable']);
        }

        //Set the current module
        $config['table'] = $this->_currentTable();
        $this->_setAssociations($config);
    }

    /**
     * Get searchable fields
     *
     * @return array field names
     */
    public function getSearchableFields()
    {
        $result = [];
        foreach ($this->getFieldsDefinitions() as $field) {
            if (!$field[static::PARAM_NON_SEARCHABLE]) {
                $result[] = $field['name'];
            }
        }

        return $result;
    }

    /**
     * Returns searchable fields properties.
     *
     * @param  array $fields searchable fields
     * @return array
     */
    public function getSearchableFieldProperties(array $fields)
    {
        $result = [];

        if (empty($fields)) {
            return $result;
        }
        foreach ($this->getFieldsDefinitions() as $field => $definitions) {
            if (in_array($field, $fields)) {
                $csvField = new CsvField($definitions);
                $type = $csvField->getType();
                $result[$field] = [
                    'type' => $type
                ];
                if ('list' === $type) {
                    $result[$field]['fieldOptions'] = $this->_getSelectOptions($csvField->getLimit());
                }
            }
        }

        return $result;
    }

    /**
     * Enable accessibility to associations primary key. Useful for
     * patching entities with associated data during updating process.
     *
     * @return array
     */
    public function enablePrimaryKeyAccess()
    {
        $result = [];
        foreach ($this->associations() as $association) {
            $result['associated'][$association->name()] = [
                'accessibleFields' => [$association->primaryKey() => true]
            ];
        }

        return $result;
    }

    /**
     * Return current table in camelCase form.
     * It adds plugin name as a prefix.
     *
     * @return string Table Name along with its prefix if found.
     */
    protected function _currentTable()
    {
        list($namespace, $alias) = namespaceSplit(get_class($this));
        $alias = substr($alias, 0, -5);
        list($plugin) = explode('\\', $namespace);
        if ($plugin === 'App') {
            return Inflector::camelize($alias);
        }

        return Inflector::camelize($plugin . '.' . $alias);
    }
}
