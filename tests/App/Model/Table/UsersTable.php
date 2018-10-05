<?php
namespace CsvMigrations\Test\App\Model\Table;

use Cake\ORM\Table;
use CsvMigrations\Model\AssociationsAwareTrait;

/**
 * Users Model
 */
class UsersTable extends Table
{
    use AssociationsAwareTrait;

    public function initialize(array $config)
    {
        parent::initialize($config);

        $this->table('users');
        $this->primaryKey('id');

        $this->addBehavior('Timestamp');
        $this->addBehavior('Qobo/Utils.Footprint');

        $this->setAssociations();
    }
}
