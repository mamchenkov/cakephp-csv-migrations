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

namespace CsvMigrations\Shell;

use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Faker\Factory;
use InvalidArgumentException;
use Qobo\Utils\ModuleConfig\ConfigType;
use Qobo\Utils\ModuleConfig\ModuleConfig;
use Qobo\Utils\Utility;

class SeedShell extends Shell
{
    /**
     * Number of records to be added for each Module.
     * @var int
     */
    protected $numberOfRecords = 20;

    /**
     * Array storing all the csv Modules.
     * @var array
     */
    protected $modules = [];

    /**
     * Array responsible to know which Modules were filled with fake data.
     * @var array
     */
    protected $modulesPolpulatedWithData = [];

    /**
     * Array that holds the module names that will skipped the process for adding data.
     *
     * @var array
     */
    protected $skipModules = [];

    /**
     * Array that is used as a stack in order to prevent the recursive loops over the modules that are referenced by themselves or by each other.
     *
     * @var array
     */
    protected $stack = [];

    /**
     * Configure option parser
     *
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function getOptionParser()
    {
        $parser = parent::getOptionParser();
        $parser->setDescription('CSV Migration Seeder');

        $parser->addOption('numberofrecords', [
            'short' => 'n',
            'help' => 'Number of fake records to create.',
            'default' => 20
        ]);

        return $parser;
    }

    /**
     * {@inheritDoc}
     */
    public function main()
    {
        // If outgoing emails are not disabled, creating numerous records
        // can cause a potential email flood due to 'assigned_to' and
        // similar associations.
        $emailTransport = Configure::read('EmailTransport.default.className');
        if (empty($emailTransport)) {
            $this->abort("Could read class name of the 'default' email transport. Cannot determine if ougoing emails are enabled or not.");
        }
        if ($emailTransport <> 'Debug') {
            $this->abort("Outgoing emails are not disabled. Aborting to avoid email flooding.  Set default email transport class name to 'Debug'");
        }

        $numberOfRecords = $this->param('numberofrecords');
        $numberOfRecords = intval($numberOfRecords);
        if ($numberOfRecords > 0) {
            $this->numberOfRecords = $numberOfRecords;
        }

        $this->modules = Utility::findDirs(Configure::readOrFail('CsvMigrations.modules.path'));
        $csvFiles = $this->getModuleCsvData($this->modules);

        //check if module has relations
        $this->modules = $this->checkModuleRelations($csvFiles);

        //First add fake data to modules that have not any relations.
        $noRelations = $this->getModulesWithoutRelations($this->modules);
        foreach ($noRelations as $moduleName) {
            $this->populateDataInModule($moduleName);
        }

        //create index based on relations.
        $relationsIndex = $this->createRelationIndex($this->modules);

        //populate data to modules with relations
        $this->hierarchicalPopulateDataIntoModules($relationsIndex);

        $this->out("Done!");
    }

    /**
     * Return a list of module names that do not have relations.
     *
     * @param mixed[] $modules modules.
     * @return mixed[]
     */
    public function getModulesWithoutRelations(array $modules): array
    {
        $result = [];
        foreach ($modules as $moduleName => $module) {
            if (isset($module['relations']) && is_array($module['relations']) && ! empty($module['relations'])) {
                continue;
            }
            $result[] = $moduleName;
        }

        return $result;
    }

    /**
     * Get all the csv module's properties
     *
     * @param string $moduleName module name.
     * @return mixed[]
     */
    protected function getCSVModuleAttr(string $moduleName): array
    {
        if (empty($this->modules[$moduleName])) {
            return [];
        }

        return $this->modules[$moduleName];
    }

    /**
     * Get field value based on type.
     *
     * @param string $type type.
     * @param string $moduleName module name.
     * @param string $listName listName.
     * @return mixed
     */
    protected function getFieldValueBasedOnType(string $type, string $moduleName = '', string $listName = '')
    {
        $faker = Factory::create();

        $value = null;

        switch ($type) {
            case 'uuid':
                $value = $faker->unique()->uuid;
                break;
            case 'url':
                $value = $faker->url;
                break;
            case 'time':
                $value = $faker->unique()->time('HH:mm');
                break;
            case 'string':
                $value = $faker->unique()->text(20);
                break;
            case 'text':
                $value = $faker->unique()->paragraph();
                break;
            case 'phone':
                $value = $faker->unique()->phoneNumber;
                break;
            case 'decimal':
                $value = $faker->unique()->randomFloat();
                break;
            case 'integer':
                $value = $faker->unique()->numberBetween();
                break;
            case 'email':
                $value = $faker->unique()->email;
                break;
            case 'datetime':
            case 'reminder':
                $value = $faker->unique()->dateTime('yyyy-MM-dd HH:mm:ss');
                break;
            case 'date':
                $value = $faker->unique()->date('yyyy-MM-dd');
                break;
            case 'boolean':
                $value = $faker->unique()->boolean();
                break;
            default:
                if (strpos($type, 'list') !== false || strpos($type, 'money') !== false || strpos($type, 'metric') !== false) {
                    //get list values
                    if (empty($listName)) {
                        $listName = $this->getStringEnclosedInParenthesis($type);
                    }
                    $list = $this->getListData($moduleName, $listName);
                    if (empty($list) || count($list) == 0) {
                        $value = null;
                        break;
                    }
                    $value = $faker->randomElement($list);
                }
                if (strpos($type, 'related') !== false) {
                    //get list values
                    $moduleName = $this->getStringEnclosedInParenthesis($type);
                    $list = $this->getModuleIds($moduleName);
                    if (empty($list) || count($list) == 0) {
                        $value = null;
                        break;
                    }
                    $value = $faker->randomElement($list);
                }
        }

        return $value;
    }

    /**
     * if the type is money,metric will return the multifield values based on the type.
     *
     * @param string $type type.
     * @param string $moduleName moduleName.
     * @param string $fieldName fieldName.
     * @return mixed[]
     */
    protected function getCombinedFieldValueBasedOnType(string $type, string $moduleName, string $fieldName): array
    {
        $values = [];

        if (strpos($type, 'money') !== false) {
            $values[$fieldName . '_amount'] = $this->getFieldValueBasedOnType('decimal', $moduleName);
            $values[$fieldName . '_currency'] = $this->getFieldValueBasedOnType($type, $moduleName);
        }
        if (strpos($type, 'metric') !== false) {
            $values[$fieldName . '_amount'] = $this->getFieldValueBasedOnType('decimal', $moduleName);
            $values[$fieldName . '_unit'] = $this->getFieldValueBasedOnType($type, $moduleName);
        }

        return $values;
    }

    /**
     * Get Module ids in an array.
     *
     * @param string $moduleName module name
     * @return string[]
     */
    protected function getModuleIds(string $moduleName): array
    {
        $table = TableRegistry::get($moduleName);
        $query = $table->find()
            ->limit(100)
            ->select($table->getPrimaryKey());

        $result = [];
        foreach ($query->all() as $entity) {
            $result[] = $entity->get($table->getPrimaryKey());
        }

        return $result;
    }

    /**
     * Get Active List (csv list) data.
     * @param string $module module name.
     * @param string $listName list name.
     * @return string[]
     */
    protected function getListData(string $module, string $listName): array
    {
        $mc = new ModuleConfig(ConfigType::LISTS(), $module, $listName);
        try {
            $config = $mc->parseToArray();
            $listData = array_key_exists('items', $config) ? $config['items'] : [];
        } catch (InvalidArgumentException $e) {
            return [];
        }

        if (empty($listData)) {
            return [];
        }

        $result = [];
        foreach ($listData as $key => $data) {
            if ($data['inactive'] == '1') {
                continue;
            }
            $result[] = $key;
        }

        return $result;
    }

    /**
     * Check module relations.
     *
     * @param mixed[] $modules modules
     * @return mixed[]
     */
    protected function checkModuleRelations(array $modules): array
    {
        $modulesWithRelations = [];

        foreach ($modules as $name => $module) {
            $module['relations'] = [];
            foreach ($module as $field) {
                if (empty($field['type'])) {
                    continue;
                }
                if (strpos($field['type'], 'related') !== false) {
                    //get related module
                    $type = $this->getStringEnclosedInParenthesis($field['type']);
                    $module['relations'][] = $type;
                }
            }
            $modulesWithRelations[$name] = $module;
        }

        return $modulesWithRelations;
    }

    /**
     * Get string enclosed in parenthesis.
     * @param string $str string word.
     * @return string
     */
    protected function getStringEnclosedInParenthesis(string $str = ''): string
    {
        preg_match_all('/\((.+?)\)/', $str, $match);

        return $match[1][0];
    }

    /**
     * Get module csv data.
     * @param string[] $modules modules
     * @return mixed[]
     */
    protected function getModuleCsvData(array $modules): array
    {
        $csvFiles = [];

        foreach ($modules as $module) {
            $mc = new ModuleConfig(ConfigType::MIGRATION(), $module);

            $config = json_encode($mc->parse());
            if (false === $config) {
                continue;
            }

            $config = json_decode($config, true);
            if (empty($config)) {
                continue;
            }

            if (! isset($csvFiles[$module])) {
                $csvFiles[$module] = [];
            }

            $csvFiles[$module] = $config;
        }

        return $csvFiles;
    }

    /**
     * Insert data into module.
     *
     * @param string $moduleName module name.
     * @return void
     */
    protected function populateDataInModule(string $moduleName): void
    {
        if ('' === $moduleName) {
            return;
        }

        if (in_array($moduleName, $this->skipModules)) {
            return;
        }

        $module = $this->getCSVModuleAttr($moduleName);

        if (empty($module)) {
            return;
        }

        $table = TableRegistry::get($moduleName);

        for ($count = 0; $count < $this->numberOfRecords; $count++) {
            $entity = $table->newEntity();

            $data = [];
            foreach ($module as $fieldName => $fieldData) {
                if (empty($fieldData['type'])) {
                    continue;
                }

                if ($this->isCombinedField($fieldData['type'])) {
                    $fields = $this->getCombinedFieldValueBasedOnType($fieldData['type'], '', (string)$fieldName);
                    foreach ($fields as $field => $value) {
                        $data[$field] = $value;
                    }
                    continue;
                }

                $fieldValue = $this->getFieldValueBasedOnType($fieldData['type']);
                if (empty($fieldValue)) {
                    continue;
                }
                $data[$fieldName] = $fieldValue;
            }
            $entity = $table->patchEntity($entity, $data);
            if ($table->save($entity)) {
                $id = $entity->id;
            }
        }
        $this->modulesPolpulatedWithData[] = $moduleName;
        $this->out($moduleName);
    }

    /**
     * Checks if the type is money or metric.
     *
     * @param string $type type.
     * @return bool
     */
    public function isCombinedField(string $type): bool
    {
        $result = false;

        if (strpos($type, 'money') !== false || strpos($type, 'metric') !== false) {
            $result = true;
        }

        return $result;
    }

    /**
     * Create relation index between modules.
     *
     * @param mixed[] $modules modules.
     * @return mixed[]
     */
    protected function createRelationIndex(array $modules): array
    {
        $index = [];

        foreach ($modules as $moduleName => $module) {
            if (! isset($module['relations'])) {
                $index[$moduleName] = [];
                continue;
            }

            if (! is_array($module['relations'])) {
                $index[$moduleName] = [];
                continue;
            }

            foreach ($module['relations'] as $relatedModule) {
                if (!empty($index[$relatedModule][$moduleName])) {
                    continue;
                }
                $index[$relatedModule][] = $moduleName;
            }
        }

        return $index;
    }

    /**
     * Hierarchical insert data into modules (based on index hierarchy).
     *
     * @param mixed[] $index index.
     * @return void
     */
    protected function hierarchicalPopulateDataIntoModules(array $index): void
    {
        foreach ($index as $moduleName => $relationModule) {
            $this->checkHierarchyForModule($moduleName, $index);
        }
    }

    /**
     * Check the hierarchy for each module recursively and populate data.
     *
     * @param string $moduleName module name.
     * @param mixed[] $index index.
     * @return void
     */
    protected function checkHierarchyForModule(string $moduleName, array $index): void
    {
        if ('' === $moduleName) {
            return;
        }

        //In case the module tried to fill from a previous loop that is still in the stack return.
        //this way we prevent infinit loops when 2 or more modules are referenced by themselves or by each other in a way that they produce circles.
        if (in_array($moduleName, $this->stack)) {
            return;
        }

        //add the current module in the stack.
        $this->stack[] = $moduleName;

        //In case the module is already filled with data.
        if (in_array($moduleName, $this->modulesPolpulatedWithData)) {
            return;
        }

        //checking the case that the module do not has any relations.
        if (! empty($index[$moduleName]) && is_array($index[$moduleName])) {
            foreach ($index[$moduleName] as $relatedModule) {
                $this->checkHierarchyForModule($relatedModule, $index);
            }
        }
        $this->populateDataInModule($moduleName);

        //remove the current module from the stack.
        unset($this->stack[$moduleName]);
    }
}
