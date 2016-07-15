<?php
namespace CsvMigrations\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class FooFixture extends TestFixture
{
    public $table = 'foo';

    // Optional. Set this property to load fixtures to a different test datasource
    public $connection = 'test';

    public $fields = [
        'id' => ['type' => 'uuid'],
        'description' => ['type' => 'text', 'null' => true],
        'name' => ['type' => 'string', 'length' => 255, 'null' => false],
        'status' => ['type' => 'string', 'length' => 255, 'null' => false],
        'type' => ['type' => 'string', 'length' => 255, 'null' => false],
        'gender' => ['type' => 'string', 'length' => 255, 'null' => true],
        'city' => ['type' => 'string', 'length' => 255, 'null' => true],
        'country' => ['type' => 'string', 'length' => 255, 'null' => true],
        'cost_amount' => ['type' => 'string', 'length' => 255, 'null' => true],
        'cost_currency' => ['type' => 'string', 'length' => 255, 'null' => true],
        'garden_area_amount' => ['type' => 'integer', 'length' => 11, 'null' => true],
        'garden_area_unit' => ['type' => 'string', 'length' => 255, 'null' => true],
        'birthdate' => ['type' => 'date', 'null' => true],
        'start_time' => ['type' => 'time', 'null' => true],
        'created' => ['type' => 'datetime'],
        'modified' => ['type' => 'datetime'],
        'is_primary' => ['type' => 'boolean', 'null' => true],
        '_constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']]
        ]
    ];

    public $records = [
        [
            'id' => 'd8c3ba90-c418-4e58-8cb6-b65c9095a2dc',
            'description' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
            'name' => 'Foobar',
            'status' => 'active',
            'type' => 'gold',
            'gender' => 'm',
            'city' => 'limassol',
            'country' => 'cy',
            'cost_amount' => '1000',
            'cost_currency' => 'eur',
            'garden_area_amount' => '50',
            'garden_area_unit' => 'm',
            'birthdate' => '1985-04-22',
            'start_time' => '16:15',
            'created' => '2016-07-01 10:39:23',
            'modified' => '2016-07-01 10:41:31',
            'is_primary' => 1
        ]
    ];
}
