<?php

/**
 * Plugin configuration
 */

use Cake\Core\Configure;
use Cake\Event\EventManager;
use CsvMigrations\Event\Model\AutoIncrementEventListener;
use CsvMigrations\Event\Model\ModelAfterSaveListener;

// get app level config
$config = Configure::read('CsvMigrations');
$config = $config ? $config : [];

// load default plugin config
Configure::load('CsvMigrations.csv_migrations');
Configure::load('CsvMigrations.currencies');

// overwrite default plugin config by app level config
Configure::write('CsvMigrations', array_replace_recursive(
    Configure::read('CsvMigrations'),
    $config
));

// get app level config
$config = Configure::read('Importer');
$config = $config ? $config : [];

// load default plugin config
Configure::load('CsvMigrations.importer');

// overwrite default plugin config by app level config
Configure::write('Importer', array_replace_recursive(
    Configure::read('Importer'),
    $config
));

EventManager::instance()->on(new AutoIncrementEventListener());
EventManager::instance()->on(new ModelAfterSaveListener());
