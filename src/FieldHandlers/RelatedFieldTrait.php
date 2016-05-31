<?php
namespace CsvMigrations\FieldHandlers;

use App\View\AppView;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use Cake\View\Helper\IdGeneratorTrait;
use CsvMigrations\FieldHandlers\BaseFieldHandler;

trait RelatedFieldTrait
{
    /**
     * Field type match pattern
     */
    protected $_fieldTypePattern = '/related\((.*?)\)/';

    /**
     * Field value separator
     *
     * @var string
     */
    protected $_separator = '&gt;';

    /**
     * Method that extracts list name from field type definition.
     *
     * @param  string $type field type
     * @return string       list name
     */
    protected function _getRelatedName($type)
    {
        $result = preg_replace($this->_fieldTypePattern, '$1', $type);

        return $result;
    }

    /**
     * Get related model's parent model properties.
     *
     * @param  array $table related model properties
     * @return void
     */
    protected function _getRelatedParentProperties($relatedProperties)
    {
        $parentTable = TableRegistry::get($relatedProperties['config']['parent']['module']);
        $foreignKey = $this->_getForeignKey($parentTable, $relatedProperties['controller']);

        return $this->_getRelatedProperties($parentTable, $relatedProperties['entity']->{$foreignKey});
    }

    /**
     * Get related model's properties.
     *
     * @param  mixed $table related table instance or name
     * @param  sting $data  query parameter value
     * @return void
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
        $result['config'] = $table->getConfig();
        // get associated entity record
        $result['entity'] = $this->_getAssociatedRecord($table, $data);
        // get related table's displayField value
        $result['dispFieldVal'] = !empty($result['entity']->{$table->displayField()})
            ? $result['entity']->{$table->displayField()}
            : null
        ;
        // get plugin and controller names
        list($result['plugin'], $result['controller']) = pluginSplit($tableName);
        // remove vendor from plugin name
        if (!is_null($result['plugin'])) {
            $pos = strpos($result['plugin'], '/');
            if ($pos !== false) {
                $result['plugin'] = substr($result['plugin'], $pos + 1);
            }
        }

        return $result;
    }

    /**
     * Get parent model association's foreign key.
     *
     * @param  \Cake\ORM\Table $table          Table instance
     * @param  string          $controllerName Controller name
     * @return string
     */
    protected function _getForeignKey(Table $table, $controllerName)
    {
        $result = null;
        foreach ($table->associations() as $association) {
            if ($controllerName === $association->className()) {
                $result = $association->foreignKey();
            }
        }

        return $result;
    }

    /**
     * Retrieve and return associated record Entity, by primary key value.
     *
     * @param  \Cake\ORM\Table $table Table instance
     * @param  string          $value Primary key value
     * @return object
     */
    protected function _getAssociatedRecord(Table $table, $value)
    {
        $query = $table->find('all', [
            'conditions' => [$table->primaryKey() => $value],
            'limit' => 1
        ]);

        return $query->first();
    }
}
