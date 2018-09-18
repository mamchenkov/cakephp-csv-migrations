<?php
/**
 * Copyright (c) Qobo Ltd. (https://www.qobo.biz)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Qobo Ltd. (https://www.qobo.biz)
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace CsvMigrations;

use ArrayObject;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\QueryInterface;
use Cake\Datasource\RepositoryInterface;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\ORM\Query;
use Cake\ORM\Table as BaseTable;
use Cake\Validation\Validator;
use CsvMigrations\Event\EventName;
use CsvMigrations\FieldHandlers\FieldHandlerFactory;
use CsvMigrations\MigrationTrait;
use CsvMigrations\Model\AssociationsAwareTrait;
use Qobo\Utils\ModuleConfig\ConfigType;
use Qobo\Utils\ModuleConfig\ModuleConfig;

/**
 * CsvMigrations Table
 *
 * All CSV modules should extend this Table
 * class for configuration and functionality.
 */
class Table extends BaseTable
{
    use AssociationsAwareTrait;
    use MigrationTrait;

    /**
     * Current user from session
     *
     * @var array
     */
    protected $_currentUser;

    /**
     * setCurrentUser
     *
     * @param array $user from Cake\Controller\Component\AuthComponent
     * @return array $_currentUser
     */
    public function setCurrentUser($user)
    {
        $this->_currentUser = $user;

        return $this->_currentUser;
    }

    /**
     * getCurrentUser
     *
     * @return array $_currentUser property
     */
    public function getCurrentUser()
    {
        return $this->_currentUser;
    }

    /**
     * Initialize
     *
     * @param array $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config)
    {
        parent::initialize($config);

        $this->addBehavior('Muffin/Trash.Trash');
        $this->addBehavior('Qobo/Utils.Footprint');

        $config = (new ModuleConfig(
            ConfigType::MODULE(),
            App::shortName($config['className'], 'Model/Table', 'Table')
        ))->parse();

        // set display field from config
        $this->setDisplayField($config->table->display_field);

        $this->setAssociations();
    }

    /**
     * Set Table validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator)
    {
        // configurable in config/csv_migrations.php
        if (! Configure::read('CsvMigrations.tableValidation')) {
            return $validator;
        }

        $className = App::shortName(get_class($this), 'Model/Table', 'Table');
        $config = (new ModuleConfig(ConfigType::MIGRATION(), $className))->parse();
        $factory = new FieldHandlerFactory();

        foreach ($config as $column) {
            $validator = $factory->setValidationRules($this, $column->name, $validator);
        }

        return $validator;
    }

    /**
     * afterSave hook
     *
     * @param \Cake\Event\Event $event from the parent afterSave
     * @param \Cake\Datasource\EntityInterface $entity from the parent afterSave
     * @param \ArrayObject $options from the parent afterSave
     * @return void
     */
    public function afterSave(Event $event, EntityInterface $entity, ArrayObject $options)
    {
        EventManager::instance()->dispatch(new Event(
            (string)EventName::MODEL_AFTER_SAVE(),
            $this,
            ['entity' => $entity, 'options' => ['current_user' => $this->getCurrentUser()]]
        ));
    }

    /**
     * getParentRedirectUrl
     *
     * Uses [parent] section of tables config.ini to define
     * where to redirect after the entity was added/edited.
     *
     * @param \Cake\Datasource\RepositoryInterface $table of the entity table
     * @param \Cake\Datasource\EntityInterface $entity of the actual table.
     *
     * @return array $result containing Cake-standard array for redirect.
     */
    public function getParentRedirectUrl(RepositoryInterface $table, EntityInterface $entity)
    {
        $config = (new ModuleConfig(ConfigType::MODULE(), $this->getAlias()))->parse();
        if (! isset($config->parent)) {
            return [];
        }

        if (! isset($config->parent->redirect)) {
            return [];
        }

        if ('parent' === $config->parent->redirect) {
            if (! isset($config->parent->module)) {
                return [];
            }

            if (! isset($config->parent->relation)) {
                return [];
            }

            return [
                'controller' => $config->parent->module,
                'action' => $entity->get($config->parent->relation) ? 'view' : 'index',
                $entity->get($config->parent->relation)
            ];
        }

        if ('self' === $config->parent->redirect) {
            return ['action' => 'view', $entity->get($table->getPrimaryKey())];
        }

        return [];
    }

    /**
     * enablePrimaryKeyAccess
     *
     * Enable accessibility to associations primary key. Useful for
     * patching entities with associated data during updating process.
     *
     * @return array
     */
    public function enablePrimaryKeyAccess()
    {
        $result = [];
        foreach ($this->associations() as $association) {
            $result['associated'][$association->name()] = [
                'accessibleFields' => [$association->primaryKey() => true]
            ];
        }

        return $result;
    }
}
