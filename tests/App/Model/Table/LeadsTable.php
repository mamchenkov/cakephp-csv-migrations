<?php

namespace CsvMigrations\Test\App\Model\Table;

use CsvMigrations\Table;

class LeadsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('leads');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
    }
}
