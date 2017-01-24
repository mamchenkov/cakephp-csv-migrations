<?php
namespace CsvMigrations;

use Cake\Core\Configure;
use CsvMigrations\CsvMigrationsUtils;
use CsvMigrations\FieldHandlers\CsvField;
use CsvMigrations\Parser\Csv\MigrationParser;
use CsvMigrations\Parser\Ini\Parser;
use CsvMigrations\PathFinder\MigrationPathFinder;
use RuntimeException;

trait MigrationTrait
{
    /**
     * Cached CSV field definitions for the current module
     *
     * @var array
     */
    protected $_fieldDefinitions = [];

    /**
     * Associated fields identifiers
     *
     * @var array
     */
    private $__assocIdentifiers = ['related'];

    /**
     * Get fields from CSV file
     *
     * This method gets all fields defined in the CSV and returns
     * them as an associative array.
     *
     * Additionally, an associative array of stub fields can be
     * passed, which will be included in the returned definitions.
     * This is useful when working with fields which are NOT part
     * of the migration.csv definitions, such as combined fields
     * and virtual fields.
     *
     * If the field exists in the CSV configuration and is passed
     * as a stub field, then the CSV definition will be preferred.
     *
     * Note that this method is called very frequently during the
     * rendering of the views, so performance is important.  For
     * this reason, parsed definitions are stored in the property
     * to avoid unnecessary processing of files and conversion of
     * data.
     *
     * @param  array $stubFields Stub fields
     * @return array             Associative array of fields and their definitions
     */
    public function getFieldsDefinitions(array $stubFields = [])
    {
        $result = [];

        // Get cached definitions
        if (!empty($this->_fieldDefinitions)) {
            $result = $this->_fieldDefinitions;
        }

        // Fetch definitions from CSV if cache is empty
        if (empty($result)) {
            if (is_callable([$this, 'alias'])) {
                $moduleName = $this->alias();
            } else {
                throw new RuntimeException("Failed getting field definitions for unknown module");
            }

            $pathFinder = new MigrationPathFinder;
            $path = $pathFinder->find($moduleName);

            // Parser knows how to make sure that the file exists.  But it can
            // also throw other exceptions, which we don't want to avoid for
            // now.
            if (is_readable($path)) {
                $parser = new MigrationParser();
                // Set fields definitions cache
                $this->_fieldDefinitions = $parser->wrapFromPath($path);
                $result = $this->_fieldDefinitions;
            }
        }

        if (empty($stubFields)) {
            return $result;
        }

        // Merge $result with $stubFields
        foreach ($stubFields as $field => $definition) {
            if (!in_array($field, array_keys($result))) {
                $result[$field] = $definition;
            }
        }

        return $result;
    }

    /**
     * Set current model table associations
     *
     * @param array $config The configuration for the Table.
     * @return void
     */
    protected function _setAssociations(array $config)
    {
        $this->_setAssociationsFromCsv($config);
        $this->_setAssociationsFromConfig($config);
    }

    /**
     * Set current model table associations from config file
     *
     * @param array $config The configuration for the Table.
     * @return void
     */
    protected function _setAssociationsFromConfig(array $config)
    {
        if (empty($this->_config['manyToMany']['modules'])) {
            return;
        }

        $manyToMany = explode(',', $this->_config['manyToMany']['modules']);

        foreach ($manyToMany as $module) {
            $this->belongsToMany($module, [
                'className' => $module
            ]);
        }
    }

    /**
     * Set current model table associations from CSV file
     *
     * @param array $config The configuration for the Table.
     * @return void
     */
    protected function _setAssociationsFromCsv(array $config)
    {
        $csvData = $this->_csvData(true);
        $csvObjData = $this->_csvDataToCsvObj($csvData);
        $csvFilteredData = $this->_csvDataFilter($csvObjData, $this->__assocIdentifiers);

        //setting up files associations for FileStorage relation
        foreach ($csvObjData as $csvModule => $fields) {
            foreach ($fields as $csvObjField) {
                if (in_array($csvObjField->getType(), ['files', 'images'])) {
                    if ($csvModule == $config['table']) {
                        $fieldName = $csvObjField->getName();
                        $assocName = CsvMigrationsUtils::createAssociationName('Burzum/FileStorage.FileStorage', $fieldName);
                        $this->hasMany($assocName, [
                            'className' => 'Burzum/FileStorage.FileStorage',
                            'foreignKey' => 'foreign_key',
                            'conditions' => [
                                'model' => $this->table(),
                                'model_field' => $fieldName,
                            ]
                        ]);
                    }
                }
            }
        }

        foreach ($csvFilteredData as $csvModule => $fields) {
            foreach ($fields as $csvObjField) {
                $assoccsvModule = $csvObjField->getAssocCsvModule();
                $fieldName = $csvObjField->getName();

                if ($config['table'] === $csvModule) {
                    $assocName = CsvMigrationsUtils::createAssociationName($assoccsvModule, $fieldName);
                    //Belongs to association of the curren running module.
                    $this->belongsTo($assocName, [
                        'className' => $assoccsvModule,
                        'foreignKey' => $fieldName
                    ]);
                } elseif ($config['table'] === $assoccsvModule) {
                    //Foreignkey found in other module
                    $assocName = CsvMigrationsUtils::createAssociationName($csvModule, $fieldName);
                    $this->hasMany($assocName, [
                        'className' => $csvModule,
                        'foreignKey' => $fieldName
                    ]);
                }
            }
        }
    }

    /**
     * Filter the CSV data by type.
     *
     * @param  array  $data  CSV data.
     * @param  array  $types Types to filter.
     * @return array         Filtered data.
     */
    protected function _csvDataFilter(array $data = [], array $types = [])
    {
        if (empty($data)) {
            return $data;
        }

        foreach ($data as $csvModule => &$fields) {
            foreach ($fields as $key => $field) {
                if (!in_array($field->getType(), $types)) {
                    unset($fields[$key]);
                }
            }
        }

        return $data;
    }

    /**
     * Convert field details into CSV object
     *
     * @see  _csvData();
     * @param  array  $data The return of _csvData function
     * @return array        An array containing CSV objects.
     */
    protected function _csvDataToCsvObj(array $data = [])
    {
        foreach ($data as $csvModule => &$fields) {
            foreach ($fields as $key => $fieldDetails) {
                $fields[$key] = new CsvField($fieldDetails);
            }
        }

        return $data;
    }

    /**
     * Get a list of all modules
     *
     * @param string $path Path to look for modules at
     * @return array
     */
    protected function _getAllModules($path = null)
    {
        $result = [];

        if (empty($path)) {
            $path = Configure::readOrFail('CsvMigrations.modules.path');
        }

        if (empty($path)) {
            return $result;
        }

        if (!file_exists($path)) {
            return $result;
        }

        if (!is_dir($path)) {
            return $result;
        }

        $dir = new \DirectoryIterator($path);
        foreach ($dir as $module) {
            if ($module->isDot()) {
                continue;
            }
            if (!$module->isDir()) {
                continue;
            }
            $result[] = $module->getFilename();
        }

        return $result;
    }

    /**
     * Get all modules data.
     *
     * @return array Modules, fields and fields types.
     */
    protected function _csvData()
    {
        $result = [];
        $path = Configure::readOrFail('CsvMigrations.modules.path');
        $modules = $this->_getAllModules($path);
        if (empty($modules)) {
            return $result;
        }
        // TODO : Remove hardcoded names
        $migrationFilename = 'db' . DIRECTORY_SEPARATOR . 'migration.csv';
        foreach ($modules as $module) {
            $moduleMigrationFilename = $path . $module . DIRECTORY_SEPARATOR . $migrationFilename;
            if (file_exists($moduleMigrationFilename)) {
                if (!isset($csvFiles[$module])) {
                    $csvFiles[$module] = [];
                }
                $csvFiles[$module][] = $moduleMigrationFilename;
            }
        }

        // Covers case where CsvMigration configuration files reside in a plugin
        $plugin = $this->_getPluginNameFromPath($path);

        if (is_null($plugin)) {
            // covers case where CsvMigration model and controller reside in
            // a plugin (even if configuration files reside in the APP level).
            $plugin = $this->_getPluginNameFromRegistryAlias();
        }

        $parser = new MigrationParser();
        foreach ($csvFiles as $csvModule => $paths) {
            if (!is_null($plugin)) {
                $csvModule = $plugin . '.' . $csvModule;
            }
            foreach ($paths as $path) {
                $result[$csvModule] = $parser->wrapFromPath($path);
            }
        }

        return $result;
    }

    /**
     * Get all reports configurations
     *
     * Used in <model>/report/<slug> method
     * to get reports from the ini file on the dynamic
     * model/table.
     *
     * @return array $config containing all reports from ini files
     */
    public function _getReports()
    {
        $result = $config = [];

        $path = Configure::readOrFail('CsvMigrations.modules.path');
        $modules = $this->_getAllModules($path);
        if (empty($modules)) {
            return $result;
        }
        // TODO : Remove hardcoded names
        $reportFilename = 'config' . DIRECTORY_SEPARATOR . 'reports.ini';
        foreach ($modules as $module) {
            $moduleReportFilename = $path . $module . DIRECTORY_SEPARATOR . $reportFilename;
            if (file_exists($moduleReportFilename)) {
                if (!isset($csvFiles[$module])) {
                    $result[$module] = [];
                }
                $result[$module][] = $moduleReportFilename;
            }
        }

        if (!empty($result)) {
            $parser = new Parser();
            foreach ($result as $model => $paths) {
                foreach ($paths as $p) {
                    $config[$model] = $parser->parseFromPath($p);
                }
            }
        }

        return $config;
    }

    /**
     * Get the name of the plugin from its path
     *
     * @param  string $path Path of the plugin.
     * @return string       Name of plugin.
     */
    protected function _getPluginNameFromPath($path)
    {
        $plugins = Configure::read('plugins');

        if (is_null($plugins)) {
            return null;
        }

        foreach ($plugins as $name => $pluginPath) {
            $pos = strpos($path, $pluginPath);
            if ($pos !== false) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Get the name of the plugin from current Table's registry alias value
     *
     * @return string Name of plugin.
     */
    protected function _getPluginNameFromRegistryAlias()
    {
        list($plugin) = pluginSplit($this->registryAlias());

        return $plugin;
    }
}
