<?php

namespace CsvMigrations\Test\TestCase\Controller;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestCase;

/**
 * CsvMigrations\Controller\DblistItemsController Test Case
 */
class DblistItemsControllerTest extends IntegrationTestCase
{
    public $fixtures = [
        'plugin.CsvMigrations.dblists',
        'plugin.CsvMigrations.dblist_items'
    ];

    private $table;

    public function setUp(): void
    {
        parent::setUp();

        $this->table = TableRegistry::get('CsvMigrations.DblistItems');

        $this->session([
            'Auth' => [
                'User' => [
                    'id' => '1',
                    'username' => 'testing'
                ],
            ]
        ]);
    }

    public function tearDown(): void
    {
        unset($this->table);

        parent::tearDown();
    }

    public function testIndex(): void
    {
        $id = '35ded6f1-e886-4f3e-bcdd-47d9c55c3ce4';

        $this->get('/csv-migrations/dblist-items/index/' . $id);
        $this->assertResponseOk();
    }

    public function testIndexWithoutItems(): void
    {
        $this->enableRetainFlashMessages();

        $id = '35ded6f1-e886-4f3e-bcdd-47d9c55c3ce4';

        // deleting all items from specific DB list
        $this->table->deleteAll(['dblist_id' => $id]);

        $this->get('/csv-migrations/dblist-items/index/' . $id);
        $this->assertRedirect();
        $this->assertSession('List is empty, do you want to add new item?', 'Flash.flash.0.message');
    }

    public function testAdd(): void
    {
        $id = '35ded6f1-e886-4f3e-bcdd-47d9c55c3ce4';
        $data = [
            'name' => 'some really really random name',
            'value' => 'some_really_really_random_name',
            'dblist_id' => $id
        ];

        $this->get('/csv-migrations/dblist-items/add/' . $id);
        $this->assertResponseOk();

        $this->post('/csv-migrations/dblist-items/add/' . $id, $data);
        $this->assertRedirect();

        $query = $this->table->find()->where($data);
        $this->assertEquals(1, $query->count());
    }

    public function testAddWithInvalidData(): void
    {
        $this->enableRetainFlashMessages();

        $id = '35ded6f1-e886-4f3e-bcdd-47d9c55c3ce4';
        $count = $this->table->find()->count();

        // trying to save entity without any data
        $this->post('/csv-migrations/dblist-items/add/' . $id, []);
        $this->assertResponseOk();
        $this->assertEquals($count, $this->table->find()->count());
        $this->assertSession('The database list item could not be saved. Please, try again.', 'Flash.flash.0.message');
    }

    public function testEdit(): void
    {
        $id = '8233ddc0-5b8a-47e6-9432-e90fcba73015';
        $data = ['name' => 'some random name'];

        $this->get('/csv-migrations/dblist-items/edit/' . $id);
        $this->assertResponseOk();

        $this->put('/csv-migrations/dblist-items/edit/' . $id, $data);
        $this->assertRedirect();

        $entity = $this->table->get($id);
        $this->assertEquals($data['name'], $entity->get('name'));
    }

    public function testEditWithInvalidData(): void
    {
        $this->enableRetainFlashMessages();

        $id = '8233ddc0-5b8a-47e6-9432-e90fcba73015';
        $data = [
            'name' => 'some random name',
            'value' => 'some_random_name',
            'dblist_id' => '35ded6f1-e886-4f3e-bcdd-47d9c55c3ce4'
        ];

        // create and persist a new entity
        $this->table->save($this->table->newEntity($data));

        $entity = $this->table->get($id);

        // trying to modify another entity's data and set it to the same data as the new persisted entity (created above)
        $this->put('/csv-migrations/dblist-items/edit/' . $id, $data);
        $this->assertResponseOk();
        $this->assertEquals($entity, $this->table->get($id));
        $this->assertSession('The database list item could not be saved. Please, try again.', 'Flash.flash.0.message');
    }

    public function testDelete(): void
    {
        $id = '8233ddc0-5b8a-47e6-9432-e90fcba73015';

        $this->delete('/csv-migrations/dblist-items/delete/' . $id);
        $this->assertRedirect();

        $query = $this->table->find()->where(['id' => $id]);
        $this->assertTrue($query->isEmpty());
    }

    public function testMoveNode(): void
    {
        $id = '8233ddc0-5b8a-47e6-9432-e90fcba73015';

        $entity = $this->table->get($id);

        $this->post('/csv-migrations/dblist-items/moveNode/' . $id . '/down');
        $this->assertRedirect();
        $this->assertNotEquals($entity, $this->table->get($id));

        $this->post('/csv-migrations/dblist-items/moveNode/' . $id . '/up');
        $this->assertEquals($entity, $this->table->get($id));
    }

    public function testMoveNodeWithInvalidAction(): void
    {
        $this->enableRetainFlashMessages();

        $id = '8233ddc0-5b8a-47e6-9432-e90fcba73015';
        $action = 'invalid_action';

        $entity = $this->table->get($id);

        $this->post('/csv-migrations/dblist-items/moveNode/' . $id . '/' . $action);
        $this->assertRedirect();
        $this->assertEquals($entity, $this->table->get($id));
        $this->assertSession(sprintf('Unknown move action "%s".', $action), 'Flash.flash.0.message');
    }
}
