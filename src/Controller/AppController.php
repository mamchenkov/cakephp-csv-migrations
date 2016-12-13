<?php
namespace CsvMigrations\Controller;

use App\Controller\AppController as BaseController;
use Cake\ORM\TableRegistry;
use CsvMigrations\FileUploadsUtils;

class AppController extends BaseController
{
    protected $_fileUploadsUtils;

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        parent::initialize();

        $this->_fileUploadsUtils = new FileUploadsUtils($this->{$this->name});

        $this->loadComponent('CsvMigrations.CsvView');
    }

    /**
     * Called before the controller action. You can use this method to configure and customize components
     * or perform logic that needs to happen before each controller action.
     *
     * @param \Cake\Event\Event $event An Event instance
     * @return void
     * @link http://book.cakephp.org/3.0/en/controllers.html#request-life-cycle-callbacks
     */
    public function beforeFilter(\Cake\Event\Event $event)
    {
        parent::beforeFilter($event);

        // pass module alias to the View
        $table = $this->loadModel();

        if ($this->Auth->user()) {
            if (method_exists($table, 'setCurrentUser')) {
                $table->setCurrentUser($this->Auth->user());
            }
        }

        if (method_exists($table, 'moduleAlias')) {
            $alias = $table->moduleAlias();
        } else {
            $alias = $table->alias();
        }
        $this->set('moduleAlias', $alias);
    }

    /**
     * Index method
     *
     * @return void
     */
    public function index()
    {
        $this->render('CsvMigrations.Common/index');
    }

    /**
     * View method
     *
     * @param string|null $id Entity id.
     * @return void
     * @throws \Cake\Network\Exception\NotFoundException When record not found.
     */
    public function view($id = null)
    {
        $entity = $this->{$this->name}->get($id, [
            'contain' => []
        ]);
        $this->set('entity', $entity);
        $this->render('CsvMigrations.Common/view');
        $this->set('_serialize', ['entity']);
    }

    /**
     * Add method
     *
     * @return mixed Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $model = $this->{$this->name};
        $entity = $model->newEntity();

        if (!empty($this->request->params['data'])) {
            $this->request->data = $this->request->params['data'];
        }

        if ($this->request->is('post')) {
            if ($this->request->data('btn_operation') == 'cancel') {
                return $this->redirect(['action' => 'index']);
            }

            $entity = $model->patchEntity($entity, $this->request->data);

            $saved = null;
            $reason = 'Please try again later.';
            // TODO: Log the error.
            try {
                $saved = $model->save($entity);
            } catch (\PDOException $e) {
                if (!empty($e->errorInfo[2])) {
                    $reason = $e->errorInfo[2];
                }
            } catch (\Exception $e) {
            }

            if ($saved) {
                $linked = $this->_fileUploadsUtils->linkFilesToEntity($entity, $model, $this->request->data);

                $this->Flash->success(__('The record has been saved.'));

                $redirectUrl = $model->getParentRedirectUrl($model, $entity);
                if (empty($redirectUrl)) {
                    return $this->redirect(['action' => 'view', $entity->{$model->primaryKey()}]);
                } else {
                    return $this->redirect($redirectUrl);
                }
            } else {
                $this->Flash->error(__('The record could not be saved. ' . $reason));
            }
        }

        $this->set(compact('entity'));
        $this->render('CsvMigrations.Common/add');
        $this->set('_serialize', ['entity']);
    }

    /**
     * Edit method
     *
     * @throws \Cake\Network\Exception\NotFoundException When record not found.
     * @param string|null $id Entity id.
     * @return mixed Redirects on successful edit, renders view otherwise.
     */
    public function edit($id = null)
    {
        $model = $this->{$this->name};
        $entity = $model->get($id, [
            'contain' => []
        ]);
        if ($this->request->is(['patch', 'post', 'put'])) {
            if ($this->request->data('btn_operation') == 'cancel') {
                return $this->redirect(['action' => 'view', $id]);
            }

            // enable accessibility to associated entity's primary key to avoid associated entity getting flagged as new
            $patchOptions = $model->enablePrimaryKeyAccess();
            $entity = $model->patchEntity($entity, $this->request->data, $patchOptions);

            $saved = null;
            $reason = 'Please try again later.';
            // TODO: Log the error.
            try {
                $saved = $model->save($entity);
            } catch (\PDOException $e) {
                if (!empty($e->errorInfo[2])) {
                    $reason = $e->errorInfo[2];
                }
            } catch (\Exception $e) {
            }

            if ($saved) {
                // handle file uploads if found in the request data
                $linked = $this->_fileUploadsUtils->linkFilesToEntity($entity, $model, $this->request->data);

                $this->Flash->success(__('The record has been saved.'));

                $redirectUrl = $model->getParentRedirectUrl($model, $entity);
                if (empty($redirectUrl)) {
                    return $this->redirect(['action' => 'view', $entity->{$model->primaryKey()}]);
                } else {
                    return $this->redirect($redirectUrl);
                }
            } else {
                $this->Flash->error(__('The record could not be saved. ' . $reason));
            }
        }
        $this->set(compact('entity'));
        $this->render('CsvMigrations.Common/edit');
        $this->set('_serialize', ['entity']);
    }

    /**
     * Delete method
     *
     * @param string|null $id Entity id.
     * @return \Cake\Network\Response|null Redirects to index.
     * @throws \Cake\Network\Exception\NotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $model = $this->{$this->name};
        $entity = $model->get($id);

        if ($model->delete($entity)) {
            $this->Flash->success(__('The record has been deleted.'));
        } else {
            $this->Flash->error(__('The record could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Unlink method
     *
     * @param string $id Entity id.
     * @param string $assocName Association Name.
     * @param string $assocId Associated Entity id.
     * @return \Cake\Network\Response|null Redirects to referer.
     * @throws \Cake\Network\Exception\NotFoundException When record not found.
     */
    public function unlink($id, $assocName, $assocId)
    {
        $this->request->allowMethod(['post']);
        $model = $this->{$this->name};
        $entity = $model->get($id);
        $assocEntity = $model->{$assocName}->get($assocId);

        // unlink associated record
        $model->{$assocName}->unlink($entity, [$assocEntity]);

        $this->Flash->success(__('The record has been unlinked.'));

        return $this->redirect($this->referer());
    }
}
