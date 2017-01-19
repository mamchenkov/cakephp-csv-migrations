<?php
namespace CsvMigrations\Shell;

use Cake\Console\ConsoleOptionParser;
use Cake\Console\Shell;
use Cake\Core\Configure;
use CsvMigrations\FieldHandlers\FieldHandlerFactory;
use CsvMigrations\Parser\Csv\ListParser;
use CsvMigrations\Parser\Csv\MigrationParser;
use CsvMigrations\Parser\Csv\ViewParser;
use CsvMigrations\Parser\Ini\Parser;
use CsvMigrations\PathFinder\ConfigPathFinder;
use CsvMigrations\PathFinder\ListPathFinder;
use CsvMigrations\PathFinder\MigrationPathFinder;
use CsvMigrations\PathFinder\ViewPathFinder;

class ValidateShell extends Shell
{
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
        $errorsCount = 0;

        $this->out('Checking CSV files and configurations');
        $this->hr();
        try {
            $modules = $this->_findCsvModules();
        } catch (\Exception $e) {
            $this->abort("Failed to find CSV modules: " . $e->getMessage());
        }

        if (empty($modules)) {
            $this->out('<warning>Did not find any CSV modules</warning>');
            exit();
        }

        $this->out('Found the following modules: ', 1, Shell::VERBOSE);
        foreach ($modules as $module => $path) {
            $this->out(' - ' . $module, 1, Shell::VERBOSE);
        }

        $errorsCount += $this->_checkConfigPresence($modules);
        $errorsCount += $this->_checkMigrationPresence($modules);
        $errorsCount += $this->_checkViewsPresence($modules);
        $errorsCount += $this->_checkConfigOptions($modules);
        $errorsCount += $this->_checkMigrationFields($modules);
        $errorsCount += $this->_checkViewsFields($modules);

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
     * @param array $validModules List of valid modules
     * @return bool True if module is valid, false otherwise
     */
    protected function _isValidModule($module, $validModules)
    {
        $result = false;

        if (in_array($module, $validModules)) {
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
            $pathFinder = new ListPathFinder;
            $path = $pathFinder->find(null, $list);
            $parser = new ListParser;
            $listItems = $parser->parseFromPath($path);
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
            $pathFinder = new MigrationPathFinder;
            $path = $pathFinder->find($module);
            $parser = new MigrationParser;
            $moduleFields = $parser->parseFromPath($path);
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
            $pathFinder = new ConfigPathFinder;
            $path = $pathFinder->find($module);
            $parser = new Parser;
            $config = $parser->parseFromPath($path);
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
     * Check if config.ini file is present for each module
     *
     * @param array $modules List of modules to check
     * @return int Count of errors found
     */
    protected function _checkConfigPresence(array $modules = [])
    {
        $errors = [];

        $this->out('Trying to find and parse the config file:', 2);
        foreach ($modules as $module => $path) {
            // Common module does not require a config
            if ($module == 'Common') {
                continue;
            }
            $moduleErrors = [];
            $this->out(' - ' . $module . ' ... ', 0);
            try {
                $pathFinder = new ConfigPathFinder;
                $path = $pathFinder->find($module);
                $parser = new Parser;
                $config = $parser->parseFromPath($path);
            } catch (\Exception $e) {
                $path = $path ? '[' . $path . ']' : '';
                $moduleErrors[] = $module . " module configuration file problem: " . $e->getMessage();
            }
            $result = empty($moduleErrors) ? '<success>OK</success>' : '<error>FAIL</error>';
            $this->out($result);
            $errors = array_merge($errors, $moduleErrors);
        }
        $this->_printCheckStatus($errors);

        return count($errors);
    }

    /**
     * Check if migration.csv file is present for each module
     *
     * @param array $modules List of modules to check
     * @return int Count of errors found
     */
    protected function _checkMigrationPresence(array $modules = [])
    {
        $errors = [];

        $this->out('Trying to find and parse the migration file:', 2);
        foreach ($modules as $module => $path) {
            // Common module does not require a migration
            if ($module == 'Common') {
                continue;
            }
            $moduleErrors = [];
            $this->out(' - ' . $module . ' ... ', 0);
            try {
                $pathFinder = new MigrationPathFinder;
                $path = $pathFinder->find($module);
                $parser = new MigrationParser;
                $result = $parser->parseFromPath($path);
            } catch (\Exception $e) {
                $this->out('<error>FAIL</error>');
                $path = $path ? '[' . $path . ']' : '';
                $moduleErrors[] = $module . " module migration file $path problem: " . $e->getMessage();
            }
            $result = empty($moduleErrors) ? '<success>OK</success>' : '<error>FAIL</error>';
            $this->out($result);
            $errors = array_merge($errors, $moduleErrors);
        }
        $this->_printCheckStatus($errors);

        return count($errors);
    }

    /**
     * Check if view files are present for each module
     *
     * @param array $modules List of modules to check
     * @return int Count of errors found
     */
    protected function _checkViewsPresence(array $modules = [])
    {
        $errors = [];
        $warnings = [];

        $views = Configure::read('CsvMigrations.actions');

        $this->out('Trying to find and parse the view files:', 2);
        foreach ($modules as $module => $path) {
            // Common module does not require views
            if ($module == 'Common') {
                continue;
            }
            $moduleErrors = [];
            $viewCounter = 0;
            $this->out(' - ' . $module . ' ... ', 0);
            foreach ($views as $view) {
                $path = '';
                try {
                    $pathFinder = new ViewPathFinder;
                    $path = $pathFinder->find($module, $view);
                } catch (\Exception $e) {
                    // It's OK for view files to be missing.
                    // For example, Files and Users modules.
                }
                // If the view file does exist, it has to be parseable.
                if (file_exists($path)) {
                    $viewCounter++;
                    try {
                        $parser = new ViewParser;
                        $result = $parser->parseFromPath($path);
                    } catch (\Exception $e) {
                        $path = $path ? '[' . $path . ']' : '';
                        $moduleErrors[] = $module . " module [$view] view file problem: " . $e->getMessage();
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
            $result = empty($moduleErrors) ? '<success>OK</success>' : '<error>FAIL</error>';
            $this->out($result);
            $errors = array_merge($errors, $moduleErrors);
        }
        $this->_printCheckStatus($errors, $warnings);

        return count($errors);
    }

    /**
     * Check configuration options for each module
     *
     * @param array $modules List of modules to check
     * @return int Count of errors found
     */
    protected function _checkConfigOptions(array $modules = [])
    {
        $errors = [];
        $warnings = [];

        $this->out('Checking configuration options:', 2);
        foreach ($modules as $module => $path) {
            // Common module does not require config
            if ($module == 'Common') {
                continue;
            }
            $moduleErrors = [];
            $this->out(' - ' . $module . ' ... ', 0);
            $config = null;
            try {
                $pathFinder = new ConfigPathFinder;
                $path = $pathFinder->find($module);
                $parser = new Parser;
                $config = $parser->parseFromPath($path);
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
                            $moduleErrors[] = $module . " config [table] section references unknown field '" . $config['table']['display_field'] . "' in 'display_field' key";
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
                                $moduleErrors[] = $module . " config [table] section references unknown field '" . $typeaheadField . "' in 'typeahead_fields' key";
                            }
                        }
                    }
                    // 'lookup_fields' key is optional, but must contain valid fields if specified
                    if (!empty($config['table']['lookup_fields'])) {
                        $lookupFields = explode(',', $config['table']['lookup_fields']);
                        foreach ($lookupFields as $lookupField) {
                            if (!$this->_isValidModuleField($module, $lookupField)) {
                                $moduleErrors[] = $module . " config [table] section references unknown field '" . $lookupField . "' in 'lookup_fields' key";
                            }
                        }
                    }
                }

                // [parent] section
                if (!empty($config['parent'])) {
                    if (empty($config['parent']['module'])) {
                        $moduleErrors[] = $module . " config [parent] section is missing 'module' key";
                    }
                    if (!empty($config['parent']['module'])) {
                        if (!$this->_isValidModule($config['parent']['module'], array_keys($modules))) {
                            $moduleErrors[] = $module . " config [parent] section references unknown module '" . $config['parent']['module'] . "' in 'module' key";
                        }
                    }
                    if (!empty($config['parent']['relation'])) {
                        if (!$this->_isRealModuleField($config['parent']['relation'], $module)) {
                            $moduleErrors[] = $module . " config [parent] section references non-real field '" . $config['parent']['relation'] . "' in 'relation' key";
                        }
                    }
                    if (!empty($config['parent']['redirect'])) {
                        if (!in_array($config['parent']['redirect'], ['self', 'parent'])) {
                            $moduleErrors[] = $module . " config [parent] section references unknown redirect type '" . $config['parent']['redirect'] . "' in 'redirect key";
                        }

                        //if redirect = parent, we force the user to mention the relation and module
                        if (in_array($config['parent']['redirect'], ['parent'])) {
                            if (empty($config['parent']['module'])) {
                                $moduleErrors[] = $module . " config [parent] requires 'module' value when redirect = parent.";
                            }

                            if (empty($config['parent']['relation'])) {
                                $moduleErrors[] = $module . " config [parent] requires 'relation' when redirect = parent.";
                            }
                        }
                    }
                }

                // [virtualFields] section
                if (!empty($config['virtualFields'])) {
                    foreach ($config['virtualFields'] as $virtualField => $realFields) {
                        $realFieldsList = explode(',', $realFields);
                        if (empty($realFieldsList)) {
                            $moduleErrors[] = $module . " config [virtualFields] section does not define real fields for '$virtualField' virtual field";
                            continue;
                        }
                        foreach ($realFieldsList as $realField) {
                            if (!$this->_isRealModuleField($module, $realField)) {
                                $moduleErrors[] = $module . " config [virtualFields] section uses a non-real field in '$virtualField' virtual field";
                            }
                        }
                    }
                }

                // [manyToMany] section
                if (!empty($config['manyToMany'])) {
                    // 'module' key is required and must contain valid modules
                    if (empty($config['manyToMany']['modules'])) {
                        $moduleErrors[] = $module . " config [manyToMany] section is missing 'modules' key";
                    } else {
                        $manyToManyModules = explode(',', $config['manyToMany']['modules']);
                        foreach ($manyToManyModules as $manyToManyModule) {
                            if (!$this->_isValidModule($manyToManyModule, array_keys($modules))) {
                                $moduleErrors[] = $module . " config [manyToMany] section references unknown module '$manyToManyModule' in 'modules' key";
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
                                $moduleErrors[] = $module . " config [notifications] section references unknown field '" . $ignoredField . "' in 'typeahead_fields' key";
                            }
                        }
                    }
                }

                // [conversion] section
                if (!empty($config['conversion'])) {
                    // 'module' key is required and must contain valid modules
                    if (empty($config['conversion']['modules'])) {
                        $moduleErrors[] = $module . " config [conversion] section is missing 'modules' key";
                    } else {
                        $conversionModules = explode(',', $config['conversion']['modules']);
                        foreach ($conversionModules as $conversionModule) {
                            // Only check for simple modules, not the vendor/plugin ones
                            if (preg_match('/^\w+$/', $conversionModule) && !$this->_isValidModule($conversionModule, array_keys($modules))) {
                                $moduleErrors[] = $module . " config [conversion] section references unknown module '$conversionModule' in 'modules' key";
                            }
                        }
                    }
                    // 'inherit' key is optional, but must contain valid modules if defined
                    if (!empty($config['conversion']['inherit'])) {
                        $inheritModules = explode(',', $config['conversion']['inherit']);
                        foreach ($inheritModules as $inheritModule) {
                            if (!$this->_isValidModule($inheritModule, array_keys($modules))) {
                                $moduleErrors[] = $module . " config [conversion] section references unknown module '$inheritModule' in 'inherit' key";
                            }
                        }
                    }
                    // 'field' key is optional, but must contain valid field and 'value' if defined
                    if (!empty($config['conversion']['field'])) {
                        // 'field' key is optional, but must contain valid field is specified
                        if (!$this->_isValidModuleField($module, $config['conversion']['field'])) {
                            $moduleErrors[] = $module . " config [conversion] section references unknown field '" . $config['conversion']['field'] . "' in 'field' key";
                        }
                        // 'value' key must be set
                        if (!isset($config['conversion']['value'])) {
                            $moduleErrors[] = $module . " config [conversion] section references 'field' but does not set a 'value' key";
                        }
                    }
                }
            }

            $result = empty($moduleErrors) ? '<success>OK</success>' : '<error>FAIL</error>';
            $this->out($result);
            $errors = array_merge($errors, $moduleErrors);
        }
        $this->_printCheckStatus($errors, $warnings);

        return count($errors);
    }

    /**
     * Check migration.csv fields
     *
     * @param array $modules List of modules to check
     * @return int Count of errors found
     */
    protected function _checkMigrationFields(array $modules = [])
    {
        $errors = [];
        $warnings = [];

        $this->out('Checking migration fields:', 2);
        foreach ($modules as $module => $path) {
            // Common module does not require migration
            if ($module == 'Common') {
                continue;
            }
            $moduleErrors = [];
            $this->out(' - ' . $module . ' ... ', 0);
            $fields = null;
            try {
                $pathFinder = new MigrationPathFinder;
                $path = $pathFinder->find($module);
                $parser = new MigrationParser;
                $fields = $parser->parseFromPath($path);
            } catch (\Exception $e) {
                // We've already reported this problem in _checkMigrationPresence();
            }

            if ($fields) {
                $seenFields = [];

                // Check each field one by one
                foreach ($fields as $field) {
                    // Field name is required
                    if (empty($field['name'])) {
                        $moduleErrors[] = $module . " migration has a field without a name";
                    } else {
                        // Check for field duplicates
                        if (in_array($field['name'], $seenFields)) {
                            $moduleErrors[] = $module . " migration specifies field '" . $field['name'] . "' more than once";
                        } else {
                            $seenFields[] = $field['name'];
                        }
                        // Field type is required
                        if (empty($field['type'])) {
                            $moduleErrors[] = $module . " migration does not specify type for field  '" . $field['name'] . "'";
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
                                $moduleErrors[] = $module . " migration specifies invalid type '" . $type . "' for field  '" . $field['name'] . "'";
                            } else {
                                switch ($type) {
                                    case 'related':
                                        // Only check for simple modules, not the vendor/plugin ones
                                        if (preg_match('/^\w+$/', $limit) && !$this->_isValidModule($limit, array_keys($modules))) {
                                            $moduleErrors[] = $module . " migration relates to unknown module '$limit' in '" . $field['name'] . "' field";
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
                                            $moduleErrors[] = $module . " migration uses unknown or empty list '$limit' in '" . $field['name'] . "' field";
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
                        $moduleErrors[] = $module . " migration is missing a required field '$requiredField'";
                    }
                }
            }

            $result = empty($moduleErrors) ? '<success>OK</success>' : '<error>FAIL</error>';
            $this->out($result);
            $errors = array_merge($errors, $moduleErrors);
        }
        $this->_printCheckStatus($errors, $warnings);

        return count($errors);
    }

    /**
     * Check fields in all views
     *
     * @param array $modules List of modules to check
     * @return int Count of errors found
     */
    protected function _checkViewsFields(array $modules = [])
    {
        $errors = [];

        $views = Configure::read('CsvMigrations.actions');

        $this->out('Checking views fields:', 2);
        foreach ($modules as $module => $path) {
            // Common module does not require views
            if ($module == 'Common') {
                continue;
            }
            $moduleErrors = [];
            $viewCounter = 0;
            $this->out(' - ' . $module . ' ... ', 0);
            foreach ($views as $view) {
                $fields = null;
                try {
                    $pathFinder = new ViewPathFinder;
                    $path = $pathFinder->find($module, $view);
                    $parser = new ViewParser;
                    $fields = $parser->parseFromPath($path);
                } catch (\Exception $e) {
                    // It's OK for view files to be missing.
                    // We already handle this in _checkViewsPresence()
                }
                // If the view file does exist, it has to be parseable.
                if ($fields) {
                    $viewCounter++;
                    foreach ($fields as $field) {
                        if (count($field) > 3) {
                            $moduleErrors[] = $module . " module [$view] view has more than 2 columns";
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
                                            $moduleErrors[] = $module . " module [$view] view reference EMBEDDED column without a module";
                                        } else {
                                            if (!$this->_isValidModule($embeddedModule, array_keys($modules))) {
                                                $moduleErrors[] = $module . " module [$view] view reference EMBEDDED column with unknown module '$embeddedModule'";
                                            }
                                        }
                                        if (empty($embeddedModuleField)) {
                                            $moduleErrors[] = $module . " module [$view] view reference EMBEDDED column without a module field";
                                        } else {
                                            if (!$this->_isValidModuleField($module, $embeddedModuleField)) {
                                                $moduleErrors[] = $module . " module [$view] view reference EMBEDDED column with unknown field '$embeddedModuleField' of module '$embeddedModule'";
                                            }
                                        }
                                        $isEmbedded = false;
                                    } else {
                                        if ($column && !$this->_isValidModuleField($module, $column)) {
                                            $moduleErrors[] = $module . " module [$view] view references unknown field '$column'";
                                        }
                                    }
                                }
                            }
                            if ($isEmbedded) {
                                $moduleErrors[] = $module . " module [$view] view incorrectly uses EMBEDDED in the last column";
                            }
                        } elseif (count($field) == 1) {
                            // index view
                            if ($field[0] && !$this->_isValidModuleField($module, $field[0])) {
                                $moduleErrors[] = $module . " module [$view] view references unknown field '" . $field[0] . "'";
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
            $result = empty($moduleErrors) ? '<success>OK</success>' : '<error>FAIL</error>';
            $this->out($result);
            $errors = array_merge($errors, $moduleErrors);
        }
        $this->_printCheckStatus($errors);

        return count($errors);
    }
}
