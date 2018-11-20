<?php
namespace CsvMigrations\Test\TestCase\Model\Table;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * CsvMigrations\Model\Table\DblistsTable Test Case
 */
class DblistsTableTest extends TestCase
{

    /**
     * Fixtures
     *
     * @var array
     */
    public $fixtures = [
        'plugin.CsvMigrations.Dblists',
        'plugin.CsvMigrations.DblistItems',
    ];

    public $Dblists;

    /**
     * setUp method
     *
     * @return void
     */
    public function setUp() : void
    {
        parent::setUp();
        $config = TableRegistry::exists('Dblists') ? [] : ['className' => 'CsvMigrations\Model\Table\DblistsTable'];
        $this->Dblists = TableRegistry::get('Dblists', $config);
    }

    /**
     * Test initialize method
     *
     * @return void
     */
    public function testInitialize() : void
    {
        $this->assertTrue($this->Dblists->hasBehavior('Timestamp'), 'Missing behavior Timestamp.');
        $assoc = $this->Dblists->getAssociation('DblistItems');
        $this->assertInstanceOf('Cake\ORM\Association\HasMany', $assoc, 'Dblists\'s association with DblistItems should be hasMany');
    }

    /**
     * Test validationDefault method
     *
     * @return void
     */
    public function testValidationDefault() : void
    {
        $validator = $this->Dblists->getValidator();
        $this->assertTrue($validator->hasField('id'), 'Missing validation for id');
        $this->assertTrue($validator->hasField('name'), 'Missing validation for name');
    }

    /**
     * Test Options query.
     *
     * @return void
     */
    public function testGetOptions() : void
    {
        $result = $this->Dblists->getOptions('categories');
        $this->assertInternalType('array', $result);
        $this->assertNotEmpty($result);

        $result = $this->Dblists->getOptions('');
        $this->assertSame([], $result);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    public function tearDown() : void
    {
        unset($this->Dblists);

        parent::tearDown();
    }
}
