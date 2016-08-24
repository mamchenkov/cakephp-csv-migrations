<?php
namespace CsvMigrations\Controller\Api;

use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Datasource\ResultSetDecorator;
use Cake\Event\Event;
use Cake\ORM\TableRegistry;
use Crud\Controller\ControllerTrait;
use CsvMigrations\CsvTrait;
use CsvMigrations\FieldHandlers\RelatedFieldTrait;
use CsvMigrations\MigrationTrait;
use CsvMigrations\Panel;
use CsvMigrations\PrettifyTrait;

class AppController extends Controller
{
    /**
     * Pretty format identifier
     */
    const FORMAT_PRETTY = 'pretty';

    use ControllerTrait;
    use MigrationTrait;
    use PrettifyTrait;
    use RelatedFieldTrait;

    public $components = [
        'RequestHandler',
        'Crud.Crud' => [
            'actions' => [
                'Crud.Index',
                'Crud.View',
                'Crud.Add',
                'Crud.Edit',
                'Crud.Delete',
                'Crud.Lookup'
            ],
            'listeners' => [
                'Crud.Api',
                'Crud.ApiPagination',
                'Crud.ApiQueryLog'
            ]
        ]
    ];

    public $paginate = [
        'page' => 1,
        'limit' => 10,
        'maxLimit' => 100,
    ];

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        parent::initialize();

        if (Configure::read('API.auth')) {
            // @link http://www.bravo-kernel.com/2015/04/how-to-add-jwt-authentication-to-a-cakephp-3-rest-api/
            $this->loadComponent('Auth', [
                // non-persistent storage, for stateless authentication
                'storage' => 'Memory',
                'authenticate' => [
                    // used for validating user credentials before the token is generated
                    'Form' => [
                        'scope' => ['Users.active' => 1]
                    ],
                    // used for token validation
                    'ADmad/JwtAuth.Jwt' => [
                        'parameter' => 'token',
                        'userModel' => 'Users',
                        'scope' => ['Users.active' => 1],
                        'fields' => [
                            'username' => 'id'
                        ],
                        'queryDatasource' => true
                    ]
                ],
                'unauthorizedRedirect' => false,
                'checkAuthIn' => 'Controller.initialize'
            ]);
        }
    }

    /**
     * View CRUD action events handling logic.
     *
     * @return \Cake\Network\Response
     */
    public function view()
    {
        $this->Crud->on('beforeFind', function (Event $event) {
            $event->subject()->repository->findByLookupFields($event->subject()->query, $event->subject()->id);
        });

        $this->Crud->on('afterFind', function (Event $event) {
            $event = $this->_prettifyEntity($event);
        });

        return $this->Crud->execute();
    }

    /**
     * Index CRUD action events handling logic.
     *
     * @return \Cake\Network\Response
     */
    public function index()
    {
        $this->Crud->on('beforePaginate', function (Event $event) {
            $event = $this->_filterByConditions($event);
        });

        $this->Crud->on('afterPaginate', function (Event $event) {
            $event = $this->_prettifyEntity($event);
        });

        return $this->Crud->execute();
    }

    /**
     * Add CRUD action events handling logic.
     *
     * @return \Cake\Network\Response
     */
    public function add()
    {
        $this->Crud->on('beforeSave', function (Event $event) {
            // get Entity's Table instance
            $table = TableRegistry::get($event->subject()->entity->source());
            $table->setAssociatedByLookupFields($event->subject()->entity);
        });

        return $this->Crud->execute();
    }

    /**
     * Edit CRUD action events handling logic.
     *
     * @return \Cake\Network\Response
     */
    public function edit()
    {
        $this->Crud->on('beforeFind', function (Event $event) {
            $event->subject()->repository->findByLookupFields($event->subject()->query, $event->subject()->id);
        });

        $this->Crud->on('afterFind', function (Event $event) {
            $event = $this->_prettifyEntity($event);
        });

        $this->Crud->on('beforeSave', function (Event $event) {
            $event->subject()->repository->setAssociatedByLookupFields($event->subject()->entity);
        });

        return $this->Crud->execute();
    }

    /**
     * Lookup CRUD action events handling logic.
     *
     * @return \Cake\Network\Response
     */
    public function lookup()
    {
        $this->Crud->on('beforeLookup', function (Event $event) {
            if (!empty($this->request->query['query'])) {
                $displayField = $this->{$this->name}->displayField();
                $this->paginate['conditions'] = [$displayField . ' LIKE' => '%' . $this->request->query['query'] . '%'];
            }
        });

        $this->Crud->on('afterLookup', function (Event $event) {
            $tableConfig = [];
            if (method_exists($this->{$this->name}, 'getConfig') && is_callable([$this->{$this->name}, 'getConfig'])) {
                $tableConfig = $this->{$this->name}->getConfig();
            }

            if (!empty($tableConfig['parent']['module'])) {
                $event->subject()->entities = $this->_prependParentModule($event->subject()->entities);
            }
        });

        return $this->Crud->execute();
    }

    /**
     * Prepend parent module display field value to resultset.
     *
     * @param  \Cake\Datasource\ResultSetDecorator $entities Entities
     * @return array
     */
    protected function _prependParentModule(ResultSetDecorator $entities)
    {
        $result = $entities->toArray();

        foreach ($result as $id => &$value) {
            $parentProperties = $this->_getRelatedParentProperties(
                $this->_getRelatedProperties($this->{$this->name}->registryAlias(), $id)
            );
            if (!empty($parentProperties['dispFieldVal'])) {
                $value = implode(' ' . $this->_separator . ' ', [
                    $parentProperties['dispFieldVal'],
                    $value
                ]);
            }
        }

        return $result;
    }


    /**
     * Panels to show.
     *
     * @return void
     */
    public function panels()
    {
        $this->request->allowMethod(['ajax', 'post']);
        $result = [
            'success' => false,
            'data' => [],
        ];
        $table = $this->loadModel();
        $tableConfig = $table->getConfig();
        $entity = $table->newEntity($this->request->data);
        $panels = Panel::getPanelNames($tableConfig) ?: [];
        foreach ($panels as $name) {
            $panel = new Panel($name, $tableConfig);
            if ($panel->evalExpression($entity)) {
                $result['success'] = true;
                $result['data'][] = $panel->getName();
            }
        }

        $this->set('result', $result);
        $this->set('_serialize', 'result');
    }

    /**
     * Before filter handler.
     *
     * @param  \Cake\Event\Event $event The event.
     * @return mixed
     * @link   http://book.cakephp.org/3.0/en/controllers/request-response.html#setting-cross-origin-request-headers-cors
     */
    public function beforeFilter(Event $event)
    {
        parent::beforeFilter($event);

        $this->response->cors($this->request)
            ->allowOrigin(['*'])
            ->allowMethods(['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'])
            ->allowHeaders(['X-CSRF-Token', 'Origin', 'X-Requested-With', 'Content-Type', 'Accept'])
            ->maxAge(300)
            ->build();

        // if request method is OPTIONS just return the response with appropriate headers.
        if ('OPTIONS' === $this->request->method()) {
            return $this->response;
        }
    }

    /**
     * Method that filters ORM records by provided conditions.
     *
     * @param  \Cake\Event\Event $event The event.
     * @return \Cake\Event\Event
     */
    protected function _filterByConditions(Event $event)
    {
        $conditions = $this->request->query('conditions');
        if (!is_null($conditions)) {
            $event->subject()->query->applyOptions(['conditions' => $conditions]);
        }

        return $event;
    }

    /**
     * Method that prepares entity(ies) to run through pretiffy logic.
     * It then returns the event object.
     *
     * @param  Cake\Event\Event $event Event instance
     * @return Cake\Event\Event
     */
    protected function _prettifyEntity(Event $event)
    {
        if (static::FORMAT_PRETTY === $this->request->query('format')) {
            $table = $event->subject()->query->repository()->registryAlias();
            $fields = array_keys($this->getFieldsDefinitions($event->subject()->query->repository()->alias()));

            if (isset($event->subject()->entities)) {
                foreach ($event->subject()->entities as $entity) {
                    $entity = $this->_prettify($entity, $table, $fields);
                }
            }

            if (isset($event->subject()->entity)) {
                $event->subject()->entity = $this->_prettify($event->subject()->entity, $table, $fields);
            }
        }

        return $event;
    }
}
