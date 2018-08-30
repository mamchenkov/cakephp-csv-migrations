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
namespace CsvMigrations\FieldHandlers;

use BadMethodCallException;
use Cake\Core\Configure;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use CsvMigrations\FieldHandlers\RelatedFieldHandler;
use Qobo\Utils\ModuleConfig\ConfigType;
use Qobo\Utils\ModuleConfig\ModuleConfig;

trait RelatedFieldTrait
{
    /**
     * Field value separator
     *
     * @var string
     */
    protected $_separator = '»';

    /**
     * Get related model's parent model properties.
     *
     * @param  array $relatedProperties related model properties
     * @return mixed $result containing parent properties
     */
    protected function _getRelatedParentProperties($relatedProperties)
    {
        $result = [];
        $parentTable = TableRegistry::get($relatedProperties['config']['parent']['module']);
        $modelName = $relatedProperties['controller'];

        /*
        prepend plugin name
         */
        if (!is_null($relatedProperties['plugin'])) {
            $modelName = $relatedProperties['plugin'] . '.' . $modelName;
        }

        $foreignKey = $this->_getForeignKey($parentTable, $modelName);

        if (empty($relatedProperties['entity']) || empty($relatedProperties['entity']->{$foreignKey})) {
            return $result;
        }

        $related = $this->_getRelatedProperties($parentTable, $relatedProperties['entity']->{$foreignKey});

        if (!empty($related['entity'])) {
            $result = $related;
        }

        return $result;
    }

    /**
     * Get related model's properties.
     *
     * @param  mixed $table related table instance or name
     * @param  sting $data  query parameter value
     * @return mixed
     */
    protected function _getRelatedProperties($table, $data)
    {
        if (!is_object($table)) {
            $tableName = $table;
            $table = TableRegistry::get($tableName);
        } else {
            $tableName = $table->registryAlias();
        }

        $result['id'] = $data;

        $result['config'] = json_decode(
            json_encode((new ModuleConfig(ConfigType::MODULE(), $table->getRegistryAlias()))->parse()),
            true
        );
        // display field
        $result['displayField'] = $table->displayField();
        // get associated entity record
        $result['entity'] = $this->_getAssociatedRecord($table, $data);
        // get related table's displayField value
        if (!empty($result['entity'])) {
            // Pass the value through related field handler
            // to properly display the user-friendly label.
            $fhf = new FieldHandlerFactory();
            $dispFieldVal = $fhf->renderValue(
                $table,
                $table->displayField(),
                $result['entity']->{$table->displayField()},
                ['renderAs' => Setting::RENDER_PLAIN_VALUE_RELATED()]
            );
            $result['dispFieldVal'] = empty($dispFieldVal) ? 'N/A' : $dispFieldVal;
        } else {
            $result['dispFieldVal'] = null;
        }
        // get plugin and controller names
        list($result['plugin'], $result['controller']) = pluginSplit($tableName);

        return $result;
    }

    /**
     * Get parent model association's foreign key.
     *
     * @param  \Cake\ORM\Table $table     Table instance
     * @param  string          $modelName Model name
     * @return string
     */
    protected function _getForeignKey(Table $table, $modelName)
    {
        $result = null;
        foreach ($table->associations() as $association) {
            if ($modelName === $association->className()) {
                $result = $association->foreignKey();
            }
        }

        return $result;
    }

    /**
     * Retrieve and return associated record Entity, by primary key value.
     * If the record has been trashed - query will return NULL.
     *
     * @param  \Cake\ORM\Table $table Table instance
     * @param  string          $value Primary key value
     * @return object
     */
    protected function _getAssociatedRecord(Table $table, $value)
    {
        $options = [
            'conditions' => [$table->primaryKey() => $value],
            'limit' => 1
        ];
        // try to fetch with trashed if finder method exists, otherwise fallback to find all
        try {
            $query = $table->find('withTrashed', $options);
        } catch (BadMethodCallException $e) {
            $query = $table->find('all', $options);
        }

        return $query->first();
    }

    /**
     * Generate input help string
     *
     * Can be used as a value for placeholder or title attributes.
     *
     * @param array $properties Input properties
     * @return string
     */
    protected function _getInputHelp($properties)
    {
        $config = (new ModuleConfig(ConfigType::MODULE(), $properties['controller']))->parse();
        $fields = $config->table->typeahead_fields;
        $virtualFields = $config->virtualFields;

        // if typeahead fields were not defined, use display field
        if (empty($fields)) {
            $fields = [$properties['displayField']];
        }

        // extract virtual fields if any
        $typeaheadFields = [];
        foreach ($fields as $fieldName) {
            if (isset($virtualFields->{$fieldName})) {
                $typeaheadFields = array_merge($typeaheadFields, $virtualFields->{$fieldName});
            } else {
                $typeaheadFields[] = $fieldName;
            }
        }

        // use typeahead fields
        return implode(', or ', array_map(function ($value) {
            return Inflector::humanize($value);
        }, $typeaheadFields));
    }

    /**
     * Get input field associated icon
     *
     * @param array $properties Input properties
     * @return string
     */
    protected function _getInputIcon($properties)
    {
        $config = (new ModuleConfig(ConfigType::MODULE(), $properties['controller']))->parse();

        // return default icon if none is defined
        if (empty($config->table->icon)) {
            return Configure::read('CsvMigrations.default_icon');
        }

        return $config->table->icon;
    }
}
