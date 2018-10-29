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
namespace CsvMigrations\Utility;

use Burzum\FileStorage\Model\Entity\FileStorage;
use Burzum\FileStorage\Storage\StorageManager;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Datasource\QueryInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\ORM\Entity;
use Cake\ORM\TableRegistry;
use Cake\ORM\Table as UploadTable;
use CsvMigrations\Model\AssociationsAwareTrait;
use Qobo\Utils\ModuleConfig\ConfigType;
use Qobo\Utils\ModuleConfig\ModuleConfig;

class FileUpload
{
    /**
     * File-Storage database table name
     */
    const FILES_STORAGE_NAME = 'Burzum/FileStorage.FileStorage';

    const TABLE_FILE_STORAGE = 'file_storage';

    /**
     * One-to-many association identifier
     */
    const ASSOCIATION_ONE_TO_MANY_ID = 'oneToMany';

    /**
     * Many-to-one association identifier
     */
    const ASSOCIATION_MANY_TO_ONE_ID = 'manyToOne';

    /**
     * Instance of Cake ORM Table
     * @var \Cake\ORM\Table
     */
    protected $_table;

    /**
     * Instance of File-Storage Association class
     *
     * @var \Cake\ORM\Association
     */
    protected $_fileStorageAssociation;

    /**
     * File-Storage table foreign key
     *
     * @var string
     */
    protected $_fileStorageForeignKey;

    /**
     * Image file extensions
     *
     * @var array
     */
    protected $_imgExtensions = ['jpg', 'png', 'jpeg', 'gif'];

    /**
     * Contructor method
     *
     * @param \Cake\ORM\Table $table Upload Table Instance
     */
    public function __construct(UploadTable $table)
    {
        $this->_table = $table;

        $this->_getFileStorageAssociationInstance();
        $this->_fileStorageForeignKey = 'foreign_key';


        // NOTE: if we don't have a predefined setup for the field
        // image versions, we add it dynamically with default thumbnail versions.
        if (empty((array)Configure::read('FileStorage.imageSizes.' . $table->getTable()))) {
            Configure::write('FileStorage.imageSizes.' . $table->getTable(), Configure::read('ThumbnailVersions'));
        }
    }

    /**
     * Getter method for supported image extensions.
     *
     * @return array
     */
    public function getImgExtensions()
    {
        return $this->_imgExtensions;
    }

    /**
     * Get instance of FileStorage association.
     *
     * @return void
     */
    protected function _getFileStorageAssociationInstance()
    {
        foreach ($this->_table->associations() as $association) {
            if ($association->className() == self::FILES_STORAGE_NAME) {
                $this->_fileStorageAssociation = $association;
                break;
            }
        }
    }

    /**
     * Get files by foreign key record.
     *
     * @param string $table Table
     * @param string $field Field name
     * @param string $id Foreign key value (UUID)
     * @return \Cake\Datasource\ResultSetInterface
     */
    public function getFiles($table, string $field, string $id) : ResultSetInterface
    {
        $assocName = AssociationsAwareTrait::generateAssociationName('Burzum/FileStorage.FileStorage', $field);
        $query = $this->_table->{$assocName}->find('all', [
            'conditions' => [
                'foreign_key' => $id,
            ]
        ]);

        $this->orderClause($query, $field);

        $result = $query->all();
        foreach ($result as $entity) {
            $entity = $this->attachThumbnails($entity);
        }

        return $result;
    }

    /**
     * Adds order clause to the provided Query based on specified field configuration.
     *
     * @see  https://github.com/QoboLtd/cakephp-utils/blob/v9.2.0/src/ModuleConfig/Parser/Schema/fields.json#L30-L40
     * @param \Cake\Datasource\QueryInterface $query Query instance
     * @param string $field Field name
     * @return \Cake\Datasource\QueryInterface
     */
    private function orderClause(QueryInterface $query, string $field) : QueryInterface
    {
        $className = App::shortName(get_class($this->_table), 'Model/Table', 'Table');
        $config = (new ModuleConfig(ConfigType::FIELDS(), $className))->parse();

        if (! property_exists($config, $field)) {
            return $query;
        }

        if (! property_exists($config->{$field}, 'orderBy')) {
            return $query;
        }

        if (! property_exists($config->{$field}, 'orderDir')) {
            return $query;
        }

        $query->order([$config->{$field}->orderBy => $config->{$field}->orderDir]);

        return $query;
    }

    /**
     * Attaches thumbnails field to FileStorage entity.
     *
     * @param \Burzum\FileStorage\Model\Entity\FileStorage $entity FileStorage entity
     * @return \Burzum\FileStorage\Model\Entity\FileStorage
     */
    private function attachThumbnails(FileStorage $entity) : FileStorage
    {
        $entity->set('thumbnails', $this->getThumbnails($entity));

        return $entity;
    }

    /**
     * File storage entity thumbnails getter.
     *
     * @param \Burzum\FileStorage\Model\Entity\FileStorage $entity File storage entity
     * @return string[]
     */
    public function getThumbnails(FileStorage $entity) : array
    {
        $versions = Configure::read('FileStorage.imageHashes.file_storage');
        if (empty($versions)) {
            return [];
        }

        $result = [];
        foreach (array_keys($versions) as $version) {
            $result[$version] = str_replace(DS, '/', $this->getThumbnail($entity, $version));
        }

        return $result;
    }

    /**
     * File storage entity thumbnail getter by version.
     *
     * @param \Burzum\FileStorage\Model\Entity\FileStorage $entity File storage entity
     * @param string $version Version name
     * @return string
     */
    public function getThumbnail(FileStorage $entity, string $version) : string
    {
        $versions = (array)Configure::read('FileStorage.imageHashes.file_storage');
        if (empty($versions)) {
            return $entity->path;
        }

        if (! array_key_exists($version, $versions)) {
            return $entity->path;
        }

        $hash = Configure::read(sprintf('FileStorage.imageHashes.file_storage.%s', $version));
        if (empty($hash)) {
            return $entity->path;
        }

        $event = new Event('ImageVersion.getVersions', $this, [
            'hash' => $hash,
            'image' => $entity,
            'version' => $version,
            'options' => [],
            'pathType' => 'url'
        ]);

        EventManager::instance()->dispatch($event);

        return $event->getResult();
    }

    /**
     * ajaxSave method
     *
     * Actual save() clone, but with optional entity, as we
     * don't have it saved yet, and saving files first.
     *
     * @param \Cake\ORM\Table $table Table instance
     * @param string $field name of the association
     * @param array $files passed via file upload input field
     * @param array $options specifying if its AJAX call or not
     *
     * @return mixed $result boolean or file_storage ID.
     */
    public function ajaxSave(UploadTable $table, $field, array $files = [], $options = [])
    {
        $result = false;

        if (empty($files)) {
            return $result;
        }

        foreach ($files as $file) {
            // file not stored and not uploaded.
            if ($this->_isInValidUpload($file['error'])) {
                continue;
            }

            $result = $this->_storeFileStorage($table, $field, ['file' => $file], $options);
            if ($result) {
                $result = [
                    'id' => $result->get('id'),
                    'path' => $result->get('path')
                ];
            }
        }

        return $result;
    }

    /**
     * File save method.
     *
     * @param  \Cake\ORM\Entity $entity Associated Entity
     * @param string $field name of the association
     * @param  array            $files  Uploaded files
     * @param  array            $options for ajax call if any
     * @return bool
     */
    public function save(Entity $entity, $field, array $files = [], $options = [])
    {
        $result = false;

        if (empty($files)) {
            return $result;
        }

        foreach ($files as $file) {
            // file not stored and not uploaded.
            if ($this->_isInValidUpload($file['error'])) {
                continue;
            }

            $result = $this->_storeFileStorage($entity, $field, ['file' => $file], $options);
        }

        return $result;
    }

    /**
     * Store to FileStorage table.
     *
     * @param  \Cake\ORM\Table $table Table instance
     * @param  string $field of the association
     * @param  array $fileData File data
     * @param  array $options for extra setup
     * @return object|bool Fresh created entity or false on unsuccesful attempts.
     * @todo $table can be typecasted to UploadTable, once deprecated method FileUploadsUtils::save() is removed.
     */
    protected function _storeFileStorage($table, $field, $fileData, $options = [])
    {
        $assocName = AssociationsAwareTrait::generateAssociationName('Burzum/FileStorage.FileStorage', $field);
        $entity = $this->_table->{$assocName}->newEntity($fileData);

        $className = App::shortName(get_class($table), 'Model/Table', 'Table');
        $mc = new ModuleConfig(ConfigType::MIGRATION(), $className);
        $fieldsDefinitions = json_decode(json_encode($mc->parse()), true);

        $fieldOption = [];
        if (!empty($fieldsDefinitions)) {
            foreach ($fieldsDefinitions as $tableField => $definition) {
                if ($tableField !== $field) {
                    continue;
                }
                $fieldOption = $definition;
                break;
            }
        }

        if (!empty($options['ajax'])) {
            //AJAX upload doesn't know anything about the entity
            //it relates to, as it's not saved yet
            $patchData = [
                'model' => $this->_table->table(),
                'model_field' => $field,
            ];
        } else {
            // @todo else statement can be removed, once deprecated method FileUploadsUtils::save() is removed.
            $patchData = [
                $this->_fileStorageForeignKey => $table->get('id'),
                'model' => $this->_table->table(),
                'model_field' => $field,
            ];
        }

        $entity = $this->_table->{$assocName}->patchEntity($entity, $patchData);

        if ($this->_table->{$assocName}->save($entity)) {
            if (!empty($fieldOption) && $fieldOption['type'] === 'images') {
                $this->createThumbnails($entity);
            }

            return $entity;
        }

        return false;
    }

    /**
     * linkFilesToEntity method
     *
     * Using AJAX upload, we're dealing with created entity,
     * and stored FileStorage files, upon saving the entity,
     * the items should be linked with 'foreign_key' field.
     *
     * @param \Cake\ORM\Entity $entity of the record
     * @param \Cake\ORM\Table $tableInstance of the entity
     * @param array $data of this->request->data containing ids.
     * @param array $options Options
     * @return mixed $result of saved/updated file entities.
     */
    public function linkFilesToEntity($entity, $tableInstance, $data = [], $options = [])
    {
        $result = [];
        $uploadFields = [];

        if (!method_exists($tableInstance, 'getFieldsDefinitions')) {
            return $result;
        }

        foreach ($tableInstance->getFieldsDefinitions() as $field => $fieldInfo) {
            if (in_array($fieldInfo['type'], ['files', 'images'])) {
                array_push($uploadFields, $fieldInfo);
            }
        }

        if (empty($uploadFields)) {
            return $result;
        }

        foreach ($uploadFields as $field) {
            $savedIds = [];
            $savedIdsField = $field['name'] . '_ids';

            // @NOTE: in case of AJAX/API calls we don't have data[Table][field]
            // notation, only data[field].
            if (isset($data[$tableInstance->alias()][$savedIdsField])) {
                $savedIds = $data[$tableInstance->alias()][$savedIdsField];
            } else {
                if (isset($data[$savedIdsField])) {
                    $savedIds = $data[$savedIdsField];
                }
            }

            if (empty($savedIds)) {
                continue;
            }

            $savedIds = array_values(array_filter($savedIds));
            $assocName = AssociationsAwareTrait::generateAssociationName(
                'Burzum/FileStorage.FileStorage',
                $field['name']
            );

            foreach ($savedIds as $fileId) {
                $record = $this->_table->{$assocName}->get($fileId);
                $record->foreign_key = $entity->id;

                $result[] = $this->_table->{$assocName}->save($record);
            }
        }

        return $result;
    }

    /**
     * File delete method.
     *
     * @param  string $id Associated Entity id
     * @return bool
     */
    public function delete($id)
    {
        $result = $this->_deleteFileAssociationRecord($id);

        return $result;
    }

    /**
     * Method that fetches and deletes document-file manyToMany association record Entity.
     *
     * @param  string $id file id
     * @return bool
     */
    protected function _deleteFileAssociationRecord($id)
    {
        $query = $this->_fileStorageAssociation->find('all', [
            'conditions' => [$this->_fileStorageForeignKey => $id]
        ]);
        $entity = $query->first();

        if (is_null($entity)) {
            return false;
        }

        return $this->_fileStorageAssociation->delete($entity);
    }

    /**
     * Method used for creating image file thumbnails.
     *
     * @param  \Cake\ORM\Entity $entity File Entity
     * @return bool
     */
    public function createThumbnails(Entity $entity)
    {
        return $this->_handleThumbnails($entity, 'ImageVersion.createVersion');
    }

    /**
     * Method used for removing image file thumbnails.
     *
     * @param  \Cake\ORM\Entity $entity File Entity
     * @return bool
     */
    protected function _removeThumbnails(Entity $entity)
    {
        return $this->_handleThumbnails($entity, 'ImageVersion.removeVersion');
    }

    /**
     * Method used for handling image file thumbnails creation and removal.
     *
     * Note that the code on this method was borrowed fromBurzum/FileStorage
     * plugin, ImageVersionShell Class _loop method.
     *
     * @param  \Cake\ORM\Entity $entity    File Entity
     * @param  string           $eventName Event name
     * @return bool
     */
    protected function _handleThumbnails(Entity $entity, $eventName)
    {
        if (!in_array(strtolower($entity->extension), $this->_imgExtensions)) {
            return false;
        }

        $operations = Configure::read('FileStorage.imageSizes.' . $entity->model);

        $storageTable = TableRegistry::get('Burzum/FileStorage.ImageStorage');
        $result = true;
        foreach ($operations as $version => $operation) {
            $payload = [
                'record' => $entity,
                'storage' => StorageManager::adapter($entity->adapter),
                'operations' => [$version => $operation],
                'versions' => [$version],
                'table' => $storageTable,
                'options' => []
            ];

            $event = new Event($eventName, $storageTable, $payload);
            EventManager::instance()->dispatch($event);

            if ('error' === $event->result[$version]['status']) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * Checks if the file is invalid from its error code.
     *
     * @see http://php.net/manual/en/features.file-upload.errors.php
     * @param  int  $error PHP validation error
     * @return bool true for invalid.
     */
    protected function _isInValidUpload($error)
    {
        return (bool)$error;
    }
}
