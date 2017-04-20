<?php
namespace CsvMigrations\Shell;

use Cake\Console\ConsoleOptionParser;
use Cake\Console\Shell;
use Cake\Core\Configure;
use CsvMigrations\FieldHandlers\FieldHandlerFactory;
use Qobo\Utils\ModuleConfig\ModuleConfig;

class ValidateShell extends Shell
{
    /**
     * @var array $modules List of known modules
     */
    protected $modules;

    /**
     * Set shell description and command line options
     *
     * @return ConsoleOptionParser
     */
    public function getOptionParser()
    {
        $parser = new ConsoleOptionParser('console');
        $parser->description('Validate CSV and configuration files of all CSV modules');

        return $parser;
    }

    /**
     * Main method for shell execution
     *
     * @return void
     */
    public function main()
    {
        $this->out('Checking CSV files and configurations');
        $this->hr();
        try {
            $this->modules = $this->_findCsvModules();
        } catch (\Exception $e) {
            $this->abort("Failed to find CSV modules: " . $e->getMessage());
        }

        if (empty($this->modules)) {
            $this->out('<warning>Did not find any CSV modules</warning>');
            exit();
        }

        $errorsCount = 0;
        foreach ($this->modules as $module => $path) {
            // Temporary skip of Common module configurations
            if ($module == 'Common') {
                continue;
            }

            $errors = [];
            $warnings = [];
            $checks = [
                '_checkConfigPresence',
                '_checkMigrationPresence',
                '_checkViewsPresence',
                '_checkConfigOptions',
                '_checkMigrationFields',
                '_checkViewsFields',
            ];

            $this->out("Checking module $module ($path)", 1);
            foreach ($checks as $check) {
                $checkResult = $this->$check($module, $path);
                $errors += $checkResult['errors'];
                $warnings += $checkResult['warnings'];
            }

            $errorsCount += count($errors);
            $this->_printCheckStatus($errors, $warnings);
        }

        if ($errorsCount) {
            $this->abort("Errors found: $errorsCount.  Validation failed!");
        }
        $this->out('<success>No errors found. Validation passed!</success>');
    }

    /**
     * Find the list of CSV modules and their paths
     *
     * @return array List of modules and their paths
     */
    protected function _findCsvModules()
    {
        $result = [];

        $path = Configure::read('CsvMigrations.modules.path');
        if (!is_readable($path)) {
            throw new \RuntimeException("[$path] is not readable");
        }
        if (!is_dir($path)) {
            throw new \RuntimeException("[$path] is not a directory");
        }

        foreach (new \DirectoryIterator($path) as $fileinfo) {
            if ($fileinfo->isDot()) {
                continue;
            }
            $result[$fileinfo->getFilename()] = $fileinfo->getPathname();
        }
        asort($result);

        return $result;
    }

    /**
     * Print the status of a particular check
     *
     * @param array $errors Array of errors to report
     * @param array $warnings Array of warnings to report
     * @return void
     */
    protected function _printCheckStatus(array $errors = [], array $warnings = [])
    {
        $this->out('');

        // Print out warnings first, if any
        if (!empty($warnings)) {
            $this->out('Warnings:');
            foreach ($warnings as $warning) {
                $this->out('<warning> - ' . $warning . '</warning>');
            }
            $this->out('');
        }

        // Print success or list of errors, if any
        if (empty($errors)) {
            $this->out('<success>All OK</success>');
        } else {
            $this->out('Errors:');
            foreach ($errors as $error) {
                $this->out('<error> - ' . $error . '</error>');
            }
        }
        $this->hr();
    }

    /**
     * Check if the given module is valid
     *
     * @param string $module Module name to check
     * @return bool True if module is valid, false otherwise
     */
    protected function _isValidModule($module)
    {
        $result = false;

        if (in_array($module, array_keys($this->modules))) {
            $result = true;
        }

        return $result;
    }

    /**
     * Check if the given list is valid
     *
     * Lists with no items are assumed to be
     * invalid.
     *
     * @param string $list List name to check
     * @return bool True if valid, false is otherwise
     */
    protected function _isValidList($list)
    {
        $result = false;

        $module = null;
        if (strpos($list, '.') !== false) {
            list($module, $list) = explode('.', $list, 2);
        }
        $listItems = [];
        try {
            $mc = new ModuleConfig(ModuleConfig::CONFIG_TYPE_LIST, null, $list);
            $listItems = $mc->parse();
        } catch (\Exception $e) {
            // We don't care about the specifics of the failure
        }

        if ($listItems) {
            $result = true;
        }

        return $result;
    }

    /**
     * Check if the given field is valid for given module
     *
     * If valid fields are not available from the migration
     * we will assume that the field is valid.
     *
     * @param string $module Module to check in
     * @param string $field Field to check
     * @return bool True if field is valid, false otherwise
     */
    protected function _isValidModuleField($module, $field)
    {
        $result = false;

        if ($this->_isRealModuleField($module, $field) || $this->_isVirtualModuleField($module, $field)) {
            $result = true;
        }

        return $result;
    }

    /**
     * Check if the field is defined in the module migration
     *
     * If the migration file does not exist or is not
     * parseable, it is assumed the field is real.  Presence
     * and validity of the migration file is checked
     * elsewhere.
     *
     * @param string $module Module to check in
     * @param string $field Field to check
     * @return bool True if field is real, false otherwise
     */
    protected function _isRealModuleField($module, $field)
    {
        $result = false;

        $moduleFields = [];
        try {
            $mc = new ModuleConfig(ModuleConfig::CONFIG_TYPE_MIGRATION, $module);
            $moduleFields = $mc->parse();
        } catch (\Exception $e) {
            // We already report issues with migration in _checkMigrationPresence()
        }

        // If we couldn't get the migration, we cannot verify if the
        // field is real or not.  To avoid unnecessary fails, we
        // assume that it's real.
        if (empty($moduleFields)) {
            return true;
        }

        foreach ($moduleFields as $moduleField) {
            if ($field == $moduleField['name']) {
                return true;
            }
        }

        return $result;
    }

    /**
     * Check if the field is defined in the module's virtual fields
     *
     * The validity of the virtual field definition is checked
     * elsewhere.  Here we only verify that the field exists in
     * the `[virtualFields]` section definition.
     *
     * @param string $module Module to check in
     * @param string $field Field to check
     * @return bool True if field is real, false otherwise
     */
    protected function _isVirtualModuleField($module, $field)
    {
        $result = false;

        $config = [];
        try {
            $mc = new ModuleConfig(ModuleConfig::CONFIG_TYPE_MODULE, $module);
            $config = $mc->parse();
        } catch (\Exception $e) {
            return $result;
        }

        if (empty($config)) {
            return $result;
        }

        if (empty($config['virtualFields'])) {
            return $result;
        }

        if (!is_array($config['virtualFields'])) {
            return $result;
        }

        foreach ($config['virtualFields'] as $virtualField => $realFields) {
            if ($virtualField == $field) {
                return true;
            }
        }

        return $result;
    }

    /**
     * Check if the field type is valid
     *
     * Migration field type needs a field handler.
     *
     * @param string $type Field type
     * @return bool True if valid, false otherwise
     */
    protected function _isValidFieldType($type)
    {
        $result = false;

        $fhf = new FieldHandlerFactory();
        if ($fhf->hasFieldHandler($type)) {
            $result = true;
        }

        return $result;
    }

    /**
     * Check if config.ini file is present for given module
     *
     * @param string $module Module name
     * @param string $path Module path
     * @return array A list of errors
     */
    protected function _checkConfigPresence($module, $path)
    {
        $errors = [];
        $warnings = [];

        $this->out(' - Module config ... ', 0);
        try {
            $mc = new ModuleConfig(ModuleConfig::CONFIG_TYPE_MODULE, $module);
            $config = $mc->parse();
        } catch (\Exception $e) {
            $parseErrors = $mc->getParserErrors();
            if (!empty($parseErrors)) {
                foreach ($parseErrors as $parseError) {
                    $errors[] = "Parser error for $module config file: $parseError";
                }
            }
            $errors[] = $module . " module configuration file problem: " . $e->getMessage();
        }
        $result = empty($errors) ? '<success>OK</success>' : '<error>FAIL</error>';
        $this->out($result);

        $result = [
            'errors' => $errors,
            'warnings' => $warnings,
        ];

        return $result;
    }

    /**
     * Check if migration.csv file is present for given module
     *
     * @param string $module Module name
     * @param string $path Module path
     * @return array A list of errors
     */
    protected function _checkMigrationPresence($module, $path)
    {
        $errors = [];
        $warnings = [];

        $this->out(' - Migration ... ', 0);
        try {
            $mc = new ModuleConfig(ModuleConfig::CONFIG_TYPE_MIGRATION, $module);
            $result = $mc->parse();
        } catch (\Exception $e) {
            $parseErrors = $mc->getParserErrors();
            if (!empty($parseErrors)) {
                foreach ($parseErrors as $parseError) {
                    $errors[] = "Parser error for $module migration file: $parseError";
                }
            }
            $errors[] = $module . " module migration file problem: " . $e->getMessage();
        }
        $result = empty($errors) ? '<success>OK</success>' : '<error>FAIL</error>';
        $this->out($result);

        $result = [
            'errors' => $errors,
            'warnings' => $warnings,
        ];

        return $result;
    }

    /**
     * Check if view files are present for given module
     *
     * @param string $module Module name
     * @param string $path Module path
     * @return array A list of errors
     */
    protected function _checkViewsPresence($module, $path)
    {
        $errors = [];
        $warnings = [];

        $views = Configure::read('CsvMigrations.actions');

        $viewCounter = 0;
        $this->out(' - Views ... ', 0);
        foreach ($views as $view) {
            $path = '';
            try {
                $mc = new ModuleConfig(ModuleConfig::CONFIG_TYPE_VIEW, $module, $view);
                $path = $mc->find();
            } catch (\Exception $e) {
                // It's OK for view files to be missing.
                // For example, Files and Users modules.
            }
            // If the view file does exist, it has to be parseable.
            if (file_exists($path)) {
                $viewCounter++;
                try {
                    $result = $mc->parse();
                } catch (\Exception $e) {
                    $parseErrors = $mc->getParserErrors();
                    if (!empty($parseErrors)) {
                        foreach ($parseErrors as $parseError) {
                            $errors[] = "Parser error for [$view] view file of [$module]: $parseError";
                        }
                    }

                    $errors[] = $module . " module [$view] view file problem: " . $e->getMessage();
                }
            } else {
                $warnings[] = $module . " module [$view] view file is missing";
            }
        }
        // Warn if the module is missing standard views
        if ($viewCounter < count($views)) {
            $this->out('<warning>' . (int)$viewCounter . ' views</warning> ... ', 0);
        } else {
            $this->out('<info>' . (int)$viewCounter . ' views</info> ... ', 0);
        }
        $result = empty($errors) ? '<success>OK</success>' : '<error>FAIL</error>';
        $this->out($result);

        $result = [
            'errors' => $errors,
            'warnings' => $warnings,
        ];

        return $result;
    }

    /**
     * Check configuration options for given module
     *
     * @param string $module Module name
     * @param string $path Module path
     * @return array A list of errors
     */
    protected function _checkConfigOptions($module, $path)
    {
        $errors = [];
        $warnings = [];

        $this->out(' - Configuration options ... ', 0);
        $config = null;
        try {
            $mc = new ModuleConfig(ModuleConfig::CONFIG_TYPE_MODULE, $module);
            $config = $mc->parse();
        } catch (\Exception $e) {
            // We've already reported this problem in _checkConfigPresence();
        }

        // Check configuration options
        if ($config) {
            // [table] section
            if (!empty($config['table'])) {
                // 'display_field' key is optional, but must contain valid field if specified
                if (!empty($config['table']['display_field'])) {
                    if (!$this->_isValidModuleField($module, $config['table']['display_field'])) {
                        $errors[] = $module . " config [table] section references unknown field '" . $config['table']['display_field'] . "' in 'display_field' key";
                    }
                } else {
                    $warnings[] = $module . " config [table] section does not specify 'display_field' key";
                }
                // 'icon' key is optional, but strongly suggested
                if (empty($config['table']['icon'])) {
                    $warnings[] = $module . " config [table] section does not specify 'icon' key";
                }
                // 'typeahead_fields' key is optional, but must contain valid fields if specified
                if (!empty($config['table']['typeahead_fields'])) {
                    $typeaheadFields = explode(',', trim($config['table']['typeahead_fields']));
                    foreach ($typeaheadFields as $typeaheadField) {
                        if (!$this->_isValidModuleField($module, $typeaheadField)) {
                            $errors[] = $module . " config [table] section references unknown field '" . $typeaheadField . "' in 'typeahead_fields' key";
                        }
                    }
                }
                // 'lookup_fields' key is optional, but must contain valid fields if specified
                if (!empty($config['table']['lookup_fields'])) {
                    $lookupFields = explode(',', $config['table']['lookup_fields']);
                    foreach ($lookupFields as $lookupField) {
                        if (!$this->_isValidModuleField($module, $lookupField)) {
                            $errors[] = $module . " config [table] section references unknown field '" . $lookupField . "' in 'lookup_fields' key";
                        }
                    }
                }
            }

            // [parent] section
            if (!empty($config['parent'])) {
                if (empty($config['parent']['module'])) {
                    $errors[] = $module . " config [parent] section is missing 'module' key";
                }
                if (!empty($config['parent']['module'])) {
                    if (!$this->_isValidModule($config['parent']['module'])) {
                        $errors[] = $module . " config [parent] section references unknown module '" . $config['parent']['module'] . "' in 'module' key";
                    }
                }
                if (!empty($config['parent']['relation'])) {
                    if (!$this->_isRealModuleField($config['parent']['relation'], $module)) {
                        $errors[] = $module . " config [parent] section references non-real field '" . $config['parent']['relation'] . "' in 'relation' key";
                    }
                }
                if (!empty($config['parent']['redirect'])) {
                    if (!in_array($config['parent']['redirect'], ['self', 'parent'])) {
                        $errors[] = $module . " config [parent] section references unknown redirect type '" . $config['parent']['redirect'] . "' in 'redirect key";
                    }

                    //if redirect = parent, we force the user to mention the relation and module
                    if (in_array($config['parent']['redirect'], ['parent'])) {
                        if (empty($config['parent']['module'])) {
                            $errors[] = $module . " config [parent] requires 'module' value when redirect = parent.";
                        }

                        if (empty($config['parent']['relation'])) {
                            $errors[] = $module . " config [parent] requires 'relation' when redirect = parent.";
                        }
                    }
                }
            }

            // [virtualFields] section
            if (!empty($config['virtualFields'])) {
                foreach ($config['virtualFields'] as $virtualField => $realFields) {
                    $realFieldsList = explode(',', $realFields);
                    if (empty($realFieldsList)) {
                        $errors[] = $module . " config [virtualFields] section does not define real fields for '$virtualField' virtual field";
                        continue;
                    }
                    foreach ($realFieldsList as $realField) {
                        if (!$this->_isRealModuleField($module, $realField)) {
                            $errors[] = $module . " config [virtualFields] section uses a non-real field in '$virtualField' virtual field";
                        }
                    }
                }
            }

            // [manyToMany] section
            if (!empty($config['manyToMany'])) {
                // 'module' key is required and must contain valid modules
                if (empty($config['manyToMany']['modules'])) {
                    $errors[] = $module . " config [manyToMany] section is missing 'modules' key";
                } else {
                    $manyToManyModules = explode(',', $config['manyToMany']['modules']);
                    foreach ($manyToManyModules as $manyToManyModule) {
                        if (!$this->_isValidModule($manyToManyModule)) {
                            $errors[] = $module . " config [manyToMany] section references unknown module '$manyToManyModule' in 'modules' key";
                        }
                    }
                }
            }

            // [notifications] section
            if (!empty($config['notifications'])) {
                // 'ignored_fields' key is optional, but must contain valid fields if specified
                if (!empty($config['notifications']['ignored_fields'])) {
                    $ignoredFields = explode(',', trim($config['notifications']['ignored_fields']));
                    foreach ($ignoredFields as $ignoredField) {
                        if (!$this->_isValidModuleField($module, $ignoredField)) {
                            $errors[] = $module . " config [notifications] section references unknown field '" . $ignoredField . "' in 'typeahead_fields' key";
                        }
                    }
                }
            }

            // [conversion] section
            if (!empty($config['conversion'])) {
                // 'module' key is required and must contain valid modules
                if (empty($config['conversion']['modules'])) {
                    $errors[] = $module . " config [conversion] section is missing 'modules' key";
                } else {
                    $conversionModules = explode(',', $config['conversion']['modules']);
                    foreach ($conversionModules as $conversionModule) {
                        // Only check for simple modules, not the vendor/plugin ones
                        if (preg_match('/^\w+$/', $conversionModule) && !$this->_isValidModule($conversionModule)) {
                            $errors[] = $module . " config [conversion] section references unknown module '$conversionModule' in 'modules' key";
                        }
                    }
                }
                // 'inherit' key is optional, but must contain valid modules if defined
                if (!empty($config['conversion']['inherit'])) {
                    $inheritModules = explode(',', $config['conversion']['inherit']);
                    foreach ($inheritModules as $inheritModule) {
                        if (!$this->_isValidModule($inheritModule)) {
                            $errors[] = $module . " config [conversion] section references unknown module '$inheritModule' in 'inherit' key";
                        }
                    }
                }
                // 'field' key is optional, but must contain valid field and 'value' if defined
                if (!empty($config['conversion']['field'])) {
                    // 'field' key is optional, but must contain valid field is specified
                    if (!$this->_isValidModuleField($module, $config['conversion']['field'])) {
                        $errors[] = $module . " config [conversion] section references unknown field '" . $config['conversion']['field'] . "' in 'field' key";
                    }
                    // 'value' key must be set
                    if (!isset($config['conversion']['value'])) {
                        $errors[] = $module . " config [conversion] section references 'field' but does not set a 'value' key";
                    }
                }
            }
        }

        $result = empty($errors) ? '<success>OK</success>' : '<error>FAIL</error>';
        $this->out($result);

        $result = [
            'errors' => $errors,
            'warnings' => $warnings,
        ];

        return $result;
    }

    /**
     * Check migration.csv fields for given module
     *
     * @param string $module Module name
     * @param string $path Module path
     * @return array A list of errors
     */
    protected function _checkMigrationFields($module, $path)
    {
        $errors = [];
        $warnings = [];

        $this->out(' - Migration fields ... ', 0);
        $fields = null;
        try {
            $mc = new ModuleConfig(ModuleConfig::CONFIG_TYPE_MIGRATION, $module);
            $fields = $mc->parse();
        } catch (\Exception $e) {
            // We've already reported this problem in _checkMigrationPresence();
        }

        if ($fields) {
            $seenFields = [];

            // Check each field one by one
            foreach ($fields as $field) {
                // Field name is required
                if (empty($field['name'])) {
                    $errors[] = $module . " migration has a field without a name";
                } else {
                    // Check for field duplicates
                    if (in_array($field['name'], $seenFields)) {
                        $errors[] = $module . " migration specifies field '" . $field['name'] . "' more than once";
                    } else {
                        $seenFields[] = $field['name'];
                    }
                    // Field type is required
                    if (empty($field['type'])) {
                        $errors[] = $module . " migration does not specify type for field  '" . $field['name'] . "'";
                    } else {
                        $type = null;
                        $limit = null;
                        // Matches:
                        // * date, time, string, and other simple types
                        // * list(something), related(Others) and other simple limits
                        // * related(Vendor/Plugin.Model) and other complex limits
                        if (preg_match('/^(\w+?)\(([\w\/\.]+?)\)$/', $field['type'], $matches)) {
                            $type = $matches[1];
                            $limit = $matches[2];
                        } else {
                            $type = $field['type'];
                        }
                        // Field type must be valid
                        if (!$this->_isValidFieldType($type)) {
                            $errors[] = $module . " migration specifies invalid type '" . $type . "' for field  '" . $field['name'] . "'";
                        } else {
                            switch ($type) {
                                case 'related':
                                    // Only check for simple modules, not the vendor/plugin ones
                                    if (preg_match('/^\w+$/', $limit) && !$this->_isValidModule($limit)) {
                                        $errors[] = $module . " migration relates to unknown module '$limit' in '" . $field['name'] . "' field";
                                    }
                                    // Documents module can be used as `files(Documents)` for a container of the uploaded files,
                                    // or as `related(Documents)` as a regular module relationship.  It's often easy to overlook
                                    // which one was desired.  Failing on either one is incorrect, as both are valid.  A
                                    // warning is needed instead for the `related(Documents)` case instead.
                                    // The only known legitimate case is in the Files, which is join table between Documents and FileStorage.
                                    if (('Documents' == $limit) && ('Files' != $module)) {
                                        $warnings[] = $module . " migration uses 'related' type for 'Documents' in '" . $field['name'] . "'. Maybe wanted 'files(Documents)'?";
                                    }
                                    break;
                                case 'list':
                                case 'money':
                                case 'metric':
                                    if (!$this->_isValidList($limit)) {
                                        $errors[] = $module . " migration uses unknown or empty list '$limit' in '" . $field['name'] . "' field";
                                    }
                                    break;
                            }
                        }
                    }
                }
            }
            // Check for the required fields
            // TODO: Allow specifying the required fields as the command line argument (for things like trashed)
            $requiredFields = [
                'id',
                'created',
                'modified',
            ];
            foreach ($requiredFields as $requiredField) {
                if (!in_array($requiredField, $seenFields)) {
                    $errors[] = $module . " migration is missing a required field '$requiredField'";
                }
            }
        }

        $result = empty($errors) ? '<success>OK</success>' : '<error>FAIL</error>';
        $this->out($result);

        $result = [
            'errors' => $errors,
            'warnings' => $warnings,
        ];

        return $result;
    }

    /**
     * Check fields in all views for given module
     *
     * @param string $module Module name
     * @param string $path Module path
     * @return array A list of errors
     */
    protected function _checkViewsFields($module, $path)
    {
        $errors = [];
        $warnings = [];

        $views = Configure::read('CsvMigrations.actions');

        $viewCounter = 0;
        $this->out(' - View fields ... ', 0);
        foreach ($views as $view) {
            $fields = null;
            try {
                $mc = new ModuleConfig(ModuleConfig::CONFIG_TYPE_VIEW, $module, $view);
                $fields = $mc->parse();
            } catch (\Exception $e) {
                // It's OK for view files to be missing.
                // We already handle this in _checkViewsPresence()
            }
            // If the view file does exist, it has to be parseable.
            if ($fields) {
                $viewCounter++;
                foreach ($fields as $field) {
                    if (count($field) > 3) {
                        $errors[] = $module . " module [$view] view has more than 2 columns";
                    } elseif (count($field) == 3) {
                        // Get rid of the first column, which is the panel name
                        array_shift($field);
                        $isEmbedded = false;
                        foreach ($field as $column) {
                            if ($column == 'EMBEDDED') {
                                $isEmbedded = true;
                                continue;
                            } else {
                                if ($isEmbedded) {
                                    list($embeddedModule, $embeddedModuleField) = explode('.', $column);
                                    if (empty($embeddedModule)) {
                                        $errors[] = $module . " module [$view] view reference EMBEDDED column without a module";
                                    } else {
                                        if (!$this->_isValidModule($embeddedModule)) {
                                            $errors[] = $module . " module [$view] view reference EMBEDDED column with unknown module '$embeddedModule'";
                                        }
                                    }
                                    if (empty($embeddedModuleField)) {
                                        $errors[] = $module . " module [$view] view reference EMBEDDED column without a module field";
                                    } else {
                                        if (!$this->_isValidModuleField($module, $embeddedModuleField)) {
                                            $errors[] = $module . " module [$view] view reference EMBEDDED column with unknown field '$embeddedModuleField' of module '$embeddedModule'";
                                        }
                                    }
                                    $isEmbedded = false;
                                } else {
                                    if ($column && !$this->_isValidModuleField($module, $column)) {
                                        $errors[] = $module . " module [$view] view references unknown field '$column'";
                                    }
                                }
                            }
                        }
                        if ($isEmbedded) {
                            $errors[] = $module . " module [$view] view incorrectly uses EMBEDDED in the last column";
                        }
                    } elseif (count($field) == 1) {
                        // index view
                        if ($field[0] && !$this->_isValidModuleField($module, $field[0])) {
                            $errors[] = $module . " module [$view] view references unknown field '" . $field[0] . "'";
                        }
                    }
                }
            }
        }
        // Warn if the module is missing standard views
        if ($viewCounter < count($views)) {
            $this->out('<warning>' . (int)$viewCounter . ' views</warning> ... ', 0);
        } else {
            $this->out('<info>' . (int)$viewCounter . ' views</info> ... ', 0);
        }
        $result = empty($errors) ? '<success>OK</success>' : '<error>FAIL</error>';
        $this->out($result);

        $result = [
            'errors' => $errors,
            'warnings' => $warnings,
        ];

        return $result;
    }
}
