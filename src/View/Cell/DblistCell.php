<?php
namespace CsvMigrations\View\Cell;

use Cake\View\Cell;

/**
 * DbList cell
 */
class DblistCell extends Cell
{

    /**
     * List of valid options that can be passed into this
     * cell's constructor.
     *
     * @var array
     */
    protected $_validCellOptions = [];

    /**
     * Render the select options.
     *
     * @param  string $field   field name
     * @param  string $list    List name
     * @param  array  $options Input options
     * @return void
     */
    public function renderInput($field, $list = null, array $options = [])
    {
        $this->loadModel('CsvMigrations.Dblists');
        $this->_createList($list);
        $selOptions = $this->Dblists->find('options', ['name' => $list]);
        $this->set(compact('field', 'list', 'options', 'selOptions'));
    }

    /**
     * Match and render the sucessfull value.
     *
     * Checks the given list if it has the given value in its list items.
     *
     * @throws RunTimeException If the value is not found
     * @param  string $listItemValue List item value
     * @param  string $list Name of the list
     * @return void
     */
    public function renderValue($listItemValue, $list = null)
    {
        $this->loadModel('CsvMigrations.Dblists');
        $this->_createList($list);
        $query = $this->Dblists->findByName($list);
        $query = $query->matching('DblistItems', function ($q) use ($listItemValue) {
            return $q->where(['DblistItems.value' => $listItemValue]);
        });
        if (!$query->isEmpty()) {
            $data = $query->first()->_matchingData['DblistItems']->get('name');
        } else {
            $data = __d('CsvMigrations', 'The value "{0}" cannot be found in the list "{1}"', $listItemValue, $list);
        }

        $this->set('data', $data);
    }

    /**
     * Create new list.
     *
     * It will fail to create a new list if the given name already exists.
     *
     * @param  string $name List's name
     * @return bool         True on sucess.
     */
    protected function _createList($name = '')
    {
        $this->loadModel('CsvMigrations.Dblists');
        if (!$this->Dblists->exists(['name' => $name])) {
            $entity = $this->Dblists->newEntity(['name' => $name]);
            return $this->Dblists->save($entity);
        }

        return false;
    }
}
