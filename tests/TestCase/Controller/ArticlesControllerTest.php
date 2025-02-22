<?php

namespace CsvMigrations\Test\TestCase\Controller;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestCase;
use CsvMigrations\Test\App\Model\Entity\Article;

/**
 * CsvMigrations\Test\App\Controller\ArticlesController Test Case
 */
class ArticlesControllerTest extends IntegrationTestCase
{
    public $fixtures = [
        'plugin.csv_migrations.articles',
        'plugin.csv_migrations.authors'
    ];

    public function setUp(): void
    {
        parent::setUp();

        $this->enableRetainFlashMessages();

        $this->session([
            'Auth.User.id' => '00000000-0000-0000-0000-000000000001'
        ]);
    }

    public function testViewUnauthenticatedFails(): void
    {
        unset($this->_session['Auth.User.id']);

        // No session data set.
        $this->get('/articles/view/00000000-0000-0000-0000-000000000001');

        $this->assertRedirectContains('/users/login');
    }

    public function testView(): void
    {
        $this->get('/articles/view/00000000-0000-0000-0000-000000000001');
        $this->markTestSkipped(
            'The viewVariable() method is affected by the deprecated method requestAction().' .
            ' Once the deprecated method is handled this test can be re-enabled.'
        );
        $this->assertResponseOk();
        $this->assertResponseContains('Name:');
        $this->assertResponseContains('Created:');
        $this->assertResponseContains('Modified:');
        $this->assertResponseContains('Foo');
    }

    public function testAddUnauthenticatedFails(): void
    {
        unset($this->_session['Auth.User.id']);

        // No session data set.
        $this->get('/articles/add');

        $this->assertRedirectContains('/users/login');
    }

    public function testAdd(): void
    {
        $this->get('/articles/add');
        $this->assertResponseOk();
        // form element and attributes
        $this->assertResponseContains('<form');
        $this->assertResponseContains('action="/articles/add"');
        $this->assertResponseContains('data-panels-url="/api/articles/panels"');
        // submit button
        $this->assertResponseContains('type="submit"');
        // input element(s) and attributes
        $this->assertResponseContains('Name');
        $this->assertResponseContains('name="Articles[name]"');
    }

    public function testAddPostData(): void
    {
        $data = [
            'name' => 'Some Unique Name'
        ];

        $this->post('/articles/add', $data);
        $this->assertResponseSuccess();

        // fetch new record
        $query = TableRegistry::get('Articles')->find()->where(['name' => $data['name']]);

        $this->assertEquals(1, $query->count());
    }

    public function testEditUnauthenticatedFails(): void
    {
        unset($this->_session['Auth.User.id']);

        // No session data set.
        $this->get('/articles/edit');

        $this->assertRedirectContains('/users/login');
    }

    public function testEdit(): void
    {
        $this->get('/articles/edit/00000000-0000-0000-0000-000000000001');
        $this->assertResponseOk();
        // form element and attributes
        $this->assertResponseContains('<form');
        $this->assertResponseContains('action="/articles/edit/00000000-0000-0000-0000-000000000001"');
        $this->assertResponseContains('data-panels-url="/api/articles/panels"');
        // submit button
        $this->assertResponseContains('type="submit"');
        // input element(s) and attributes
        $this->assertResponseContains('Name');
        $this->assertResponseContains('name="Articles[name]"');
        $this->assertResponseContains('value="Foo"');
    }

    public function testEditPostData(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';

        $data = [
            'name' => 'Some Unique Name'
        ];

        $this->post('/articles/edit/' . $id, $data);
        $this->assertResponseSuccess();

        // fetch modified record
        $entity = TableRegistry::get('Articles')->get($id);

        $this->assertEquals($data['name'], $entity->get('name'));
    }

    public function testEditPutData(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';

        $data = [
            'name' => 'Some Unique Name'
        ];

        $this->put('/articles/edit/' . $id, $data);
        $this->assertResponseSuccess();

        // fetch modified record
        $entity = TableRegistry::get('Articles')->get($id);

        $this->assertEquals($data['name'], $entity->get('name'));
    }

    public function testDeleteUnauthenticatedFails(): void
    {
        unset($this->_session['Auth.User.id']);

        // No session data set.
        $this->delete('/articles/delete/00000000-0000-0000-0000-000000000001');

        $this->assertRedirect(['controller' => 'Users', 'action' => 'login']);
    }

    public function testDeleteGetRequest(): void
    {
        $this->get('/articles/delete/00000000-0000-0000-0000-000000000001');
        $this->assertResponseError();
    }

    public function testDeleteData(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';

        $this->delete('/articles/delete/' . $id);
        $this->assertResponseSuccess();

        // try to fetch deleted record
        $query = TableRegistry::get('Articles')->find()->where(['id' => $id]);
        $this->assertEquals(0, $query->count());
    }

    public function testDeletePostData(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';

        $this->post('/articles/delete/' . $id);
        $this->assertResponseSuccess();

        // try to fetch deleted record
        $query = TableRegistry::get('Articles')->find()->where(['id' => $id]);
        $this->assertEquals(0, $query->count());
    }

    public function testBatchGetRequest(): void
    {
        $this->get('/articles/batch/edit');
        $this->assertResponseError();
    }

    public function testBatchDelete(): void
    {
        $table = TableRegistry::get('Articles');
        $initialCount = $table->find('all')->count();

        $data = [
            'batch' => [
                'ids' => [
                    '00000000-0000-0000-0000-000000000001',
                    '00000000-0000-0000-0000-000000000002'
                ]
            ]
        ];

        $this->post('/articles/batch/delete', $data);
        $this->assertResponseSuccess();
        $this->assertSession('2 of 2 selected records have been deleted.', 'Flash.flash.0.message');

        $this->assertSame($initialCount - 2, $table->find('all')->count());
    }

    public function testBatchDeleteNoIds(): void
    {
        $this->post('/articles/batch/delete');
        $this->assertRedirect('/');
        $this->assertSession('No records selected.', 'Flash.flash.0.message');
    }

    public function testBatchEdit(): void
    {
        $data = [
            'batch' => [
                'ids' => [
                    '00000000-0000-0000-0000-000000000001',
                    '00000000-0000-0000-0000-000000000002'
                ]
            ]
        ];
        $this->post('/articles/batch/edit', $data);
        $this->assertResponseSuccess();

        $entity = $this->viewVariable('entity');

        $this->assertInstanceOf(Article::class, $entity);
        $this->assertTrue($entity->isNew());
    }

    public function testBatchEditNoIds(): void
    {
        $this->post('/articles/batch/edit');
        $this->assertRedirect('/');
        $this->assertSession('No records selected.', 'Flash.flash.0.message');
    }

    public function testBatchEditExecute(): void
    {
        $data = [
            'batch' => [
                'execute' => true,
                'ids' => [
                    '00000000-0000-0000-0000-000000000001',
                    '00000000-0000-0000-0000-000000000002'
                ]
            ],
            'Articles' => [
                'name' => 'Batch edit article name'
            ]
        ];

        $this->post('/articles/batch/edit', $data);
        $this->assertRedirect('/');
        $this->assertSession('2 of 2 selected records have been updated.', 'Flash.flash.0.message');

        $query = TableRegistry::get('Articles')->find()->where(['id IN' => $data['batch']['ids']]);
        foreach ($query->all() as $entity) {
            $this->assertEquals($data['Articles']['name'], $entity->get('name'));
        }
    }

    public function testBatchEditExecuteNoIds(): void
    {
        $data = [
            'batch' => [
                'execute' => true
            ]
        ];

        $this->post('/articles/batch/edit', $data);
        $this->assertRedirect('/');
        $this->assertSession('No records selected.', 'Flash.flash.0.message');
    }

    public function testBatchEditExecuteNoData(): void
    {
        $data = [
            'batch' => [
                'execute' => true,
                'ids' => [
                    '00000000-0000-0000-0000-000000000001',
                    '00000000-0000-0000-0000-000000000002'
                ]
            ]
        ];

        $this->post('/articles/batch/edit', $data);
        $this->assertResponseSuccess();
        $this->assertSession('Selected records could not be updated. No changes provided.', 'Flash.flash.0.message');
    }
}
