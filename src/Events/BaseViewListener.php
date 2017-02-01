<?php
namespace CsvMigrations\Events;

use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Event\EventListenerInterface;
use Cake\Log\Log;
use Cake\Network\Request;
use Cake\ORM\AssociationCollection;
use Cake\ORM\Entity;
use Cake\ORM\Query;
use Cake\Utility\Inflector;
use CsvMigrations\FieldHandlers\CsvField;
use CsvMigrations\FieldHandlers\FieldHandlerFactory;
use CsvMigrations\Parser\Csv\MigrationParser;
use CsvMigrations\Parser\Csv\ViewParser;
use CsvMigrations\PathFinder\MigrationPathFinder;
use CsvMigrations\PathFinder\ViewPathFinder;
use CsvMigrations\PrettifyTrait;
use InvalidArgumentException;

abstract class BaseViewListener implements EventListenerInterface
{
    use PrettifyTrait;

    /**
     * Pretty format identifier
     */
    const FORMAT_PRETTY = 'pretty';

    /**
     * Datatables format identifier
     */
    const FORMAT_DATATABLES = 'datatables';

    /**
     * File association class name
     */
    const FILE_CLASS_NAME = 'Burzum/FileStorage.FileStorage';

    /**
     * Current module fields list which are associated with files
     *
     * @var array
     */
    protected $_fileAssociationFields = [];

    /**
     * Wrapper method that checks if Table instance has method 'findByLookupFields'
     * and if it does, it calls it, passing along the required arguments.
     *
     * @param  \Cake\ORM\Query   $query the Query
     * @param  \Cake\Event\Event $event Event instance
     * @return void
     */
    protected function _lookupFields(Query $query, Event $event)
    {
        $methodName = 'findByLookupFields';
        $table = $event->subject()->{$event->subject()->name};
        if (!method_exists($table, $methodName) || !is_callable([$table, $methodName])) {
            return;
        }
        $id = $event->subject()->request['pass'][0];

        $table->{$methodName}($query, $id);
    }

    /**
     * Wrapper method that checks if Table instance has method 'setAssociatedByLookupFields'
     * and if it does, it calls it, passing along the required arguments.
     *
     * @param  \Cake\ORM\Entity  $entity Entity
     * @param  \Cake\Event\Event $event  Event instance
     * @return void
     */
    protected function _associatedByLookupFields(Entity $entity, Event $event)
    {
        $methodName = 'setAssociatedByLookupFields';
        $table = $event->subject()->{$event->subject()->name};
        if (!method_exists($table, $methodName) || !is_callable([$table, $methodName])) {
            return;
        }

        $table->{$methodName}($entity);
    }

    /**
     * Method that retrieves and returns csv migration fields.
     *
     * @param  Request $request Request object
     * @return array
     */
    protected function _getMigrationFields(Request $request)
    {
        $result = [];

        try {
            $pathFinder = new MigrationPathFinder;
            $path = $pathFinder->find($request->controller);

            $parser = new MigrationParser();
            $result = $parser->wrapFromPath($path);
        } catch (InvalidArgumentException $e) {
            Log::error($e);
        }

        return $result;
    }

    /**
     * Method that fetches action fields from the corresponding csv file.
     *
     * @param  \Cake\Network\Request $request Request object
     * @param  string                $action  Action name
     * @return array
     */
    protected function _getActionFields(Request $request, $action = null)
    {
        $result = [];

        $controller = $request->controller;

        if (is_null($action)) {
            $action = $request->action;
        }

        try {
            $pathFinder = new ViewPathFinder;
            $path = $pathFinder->find($controller, $action);

            $parser = new ViewParser();
            $result = $parser->parseFromPath($path);
        } catch (InvalidArgumentException $e) {
            Log::error($e);
        }

        return $result;
    }

    /**
     * Method that converts csv action fields to database fields and returns their names.
     *
     * @param  array  $fields action fields
     * @param  Event  $event  Event instance
     * @return array
     */
    protected function _databaseFields(array $fields, Event $event)
    {
        $result = [];

        $migrationFields = $this->_getMigrationFields($event->subject()->request);
        if (empty($migrationFields)) {
            return $result;
        }

        $fhf = new FieldHandlerFactory();
        foreach ($fields as $field) {
            if (!array_key_exists($field, $migrationFields)) {
                $result[] = $field;
                continue;
            }

            $csvField = new CsvField($migrationFields[$field]);
            foreach ($fhf->fieldToDb($csvField) as $dbField) {
                $result[] = $dbField->getName();
            }
        }

        $virtualFields = $event->subject()->{$event->subject()->name}->getVirtualFields();

        if (empty($virtualFields)) {
            return $result;
        }

        // handle virtual fields
        foreach ($fields as $k => $field) {
            if (!isset($virtualFields[$field])) {
                continue;
            }
            // remove virtual field
            unset($result[$k]);

            // add db fields
            $result = array_merge($result, $virtualFields[$field]);
        }

        return $result;
    }

    /**
     * Method for including files.
     *
     * @param  Entity $entity Entity
     * @param  Event  $event  Event instance
     * @return void
     * @todo   this method is very hardcoded and has been added because of an issue with the soft delete
     *         plugin (https://github.com/UseMuffin/Trash), which affects contain() functionality with
     *         belongsTo associations. Once the issue is resolved this method can be removed.
     */
    protected function _includeFiles(Entity $entity, Event $event)
    {
        $associations = $event->subject()->{$event->subject()->name}->associations();

        foreach ($associations as $docAssoc) {
            if ('Documents' !== $docAssoc->className()) {
                continue;
            }

            // get id from current entity
            $id = $entity->{$docAssoc->foreignKey()};

            // skip if id is empty
            if (empty($id)) {
                continue;
            }

            // generate property name from association name (example: photos_document)
            $docPropertyName = $this->_associationPropertyName($docAssoc->name());
            $entity->{$docPropertyName} = $docAssoc->target()->get($id);

            foreach ($docAssoc->target()->associations() as $fileAssoc) {
                if ('Files' !== $fileAssoc->className()) {
                    continue;
                }

                $query = $fileAssoc->target()->find('all', [
                    'conditions' => [$fileAssoc->foreignKey() => $entity->{$docPropertyName}->id]
                ]);

                // generate property name from association name (document_id_files)
                $filePropertyName = Inflector::underscore($fileAssoc->name());
                $entity->{$docPropertyName}->{$filePropertyName} = $query->all();

                foreach ($fileAssoc->target()->associations() as $fileStorageAssoc) {
                    if ('Burzum/FileStorage.FileStorage' !== $fileStorageAssoc->className()) {
                        continue;
                    }

                    $foreignKey = $fileStorageAssoc->foreignKey();
                    // generate property name from association name (file_id_file_storage_file_storage)
                    $fileStoragePropertyName = $this->_associationPropertyName($fileStorageAssoc->name());

                    foreach ($entity->{$docPropertyName}->{$filePropertyName} as $file) {
                        $fileStorage = $fileStorageAssoc->target()->get($file->{$foreignKey});
                        $file->{$fileStoragePropertyName} = $fileStorage;
                    }
                }
            }
        }
    }

    /**
     * Method that generates property name for belongsTo and HasOne associations.
     *
     * @param  string $name Association name
     * @return string
     */
    protected function _associationPropertyName($name)
    {
        list(, $name) = pluginSplit($name);

        return Inflector::underscore(Inflector::singularize($name));
    }

    /**
     * Method responsible for retrieving current Table's file associations
     *
     * @param  Cake\ORM\Table $table Table instance
     * @return array
     */
    protected function _getFileAssociations(Table $table)
    {
        $result = [];

        foreach ($table->associations() as $association) {
            if (static::FILE_CLASS_NAME !== $association->className()) {
                continue;
            }

            $result[] = $association->name();
        }

        return $result;
    }

    /**
     * Method that retrieve's Table association names
     * to be passed to the ORM Query.
     *
     * Nested associations can travel as many levels deep
     * as defined in the parameter array. Using the example
     * array below, our code will look for a direct association
     * with class name 'Documents'. If found, it will add the
     * association's name to the result array and it will loop
     * through its associations to look for a direct association
     * with class name 'Files'. If found again, it will add it to
     * the result array (nested within the Documents association name)
     * and will carry on until it runs out of nesting levels or
     * matching associations.
     *
     * Example array:
     * ['Documents', 'Files', 'Burzum/FileStorage.FileStorage']
     *
     * Example result:
     * [
     *     'PhotosDocuments' => [
     *         'DocumentIdFiles' => [
     *             'FileIdFileStorageFileStorage' => []
     *         ]
     *     ]
     * ]
     *
     * @param  Cake\ORM\AssociationCollection $associations       Table associations
     * @param  array                          $nestedAssociations Nested associations
     * @param  bool                           $onlyNested         Flag for including only nested associations
     * @return array
     */
    protected function _containAssociations(
        AssociationCollection $associations,
        array $nestedAssociations = [],
        $onlyNested = false
    ) {
        $result = [];

        foreach ($associations as $association) {
            if (!$onlyNested) {
                $result[$association->name()] = [];
            }

            if (empty($nestedAssociations)) {
                continue;
            }

            foreach ($nestedAssociations as $nestedAssociation) {
                if ($nestedAssociation !== $association->className()) {
                    continue;
                }

                $result[$association->name()] = [
                    'DocumentIdFiles' => [
                        'FileIdFileStorageFileStorage' => []
                    ]
                ];
            }
        }

        return $result;
    }

    /**
     * Convert Entity resource values to strings.
     * Temporary fix for bug with resources and json_encode() (see link).
     *
     * @param  \Cake\ORM\Entity $entity Entity
     * @return void
     * @link   https://github.com/cakephp/cakephp/issues/9658
     */
    protected function _resourceToString(Entity $entity)
    {
        $fields = array_keys($entity->toArray());
        foreach ($fields as $field) {
            // handle belongsTo associated data
            if ($entity->{$field} instanceof Entity) {
                $this->_resourceToString($entity->{$field});
            }

            // handle hasMany associated data
            if (is_array($entity->{$field})) {
                if (empty($entity->{$field})) {
                    continue;
                }

                foreach ($entity->{$field} as $associatedEntity) {
                    if (!$associatedEntity instanceof Entity) {
                        continue;
                    }

                    $this->_resourceToString($associatedEntity);
                }
            }

            if (is_resource($entity->{$field})) {
                $entity->{$field} = stream_get_contents($entity->{$field});
            }
        }
    }
}
