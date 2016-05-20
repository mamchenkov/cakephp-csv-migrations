<?php
namespace CsvMigrations;

use Cake\Core\Configure;

trait ConfigurationTrait
{
    /**
     * Table/module configuration
     *
     * @var array
     */
    protected $_config = [];

    /**
     * Config filename
     *
     * @var string
     */
    protected $_filename = 'config';

    /**
     * Config file extension
     *
     * @var string
     */
    protected $_extension = 'ini';

    /**
     * Searchable flag
     *
     * @var boolean
     */
    protected $_searchable = false;

    /**
     * Module alias
     *
     * @var string
     */
    protected $_moduleAlias;

    /**
     * Method that returns table configuration.
     *
     * @return array
     */
    public function getConfig()
    {
        return $this->_config;
    }

    /**
     * Method that sets table configuration.
     *
     * @return void
     */
    protected function _setConfiguration()
    {
        $path = Configure::read('CsvMigrations.migrations.path');
        $path .= $this->alias() . DS . $this->_filename . '.' . $this->_extension;
        if (file_exists($path)) {
            $this->_config = parse_ini_file($path, true);
        }
    }

    /**
     * Returns the searchable flag or sets a new one
     *
     * @param  bool|null $searchable sets module as (not) searchable
     * @return bool
     */
    public function isSearchable($searchable = null)
    {
        if (!is_null($searchable)) {
            $this->_searchable = (bool)$searchable;
        }

        return $this->_searchable;
    }

    /**
     * Returns the module alias or sets a new one
     *
     * @param  string|null $alias sets a new name to be used as module alias
     * @return string
     */
    public function moduleAlias($alias = null)
    {
        if (!is_null($alias)) {
            $this->_moduleAlias = $alias;
        }

        if (is_null($this->_moduleAlias)) {
            $this->_moduleAlias = $this->alias();
        }

        return $this->_moduleAlias;
    }
}
