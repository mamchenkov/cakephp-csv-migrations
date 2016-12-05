<?php
namespace CsvMigrations;

use Burzum\FileStorage\Storage\StorageManager;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\ORM\Entity;
use Cake\ORM\TableRegistry;
use Cake\ORM\Table as UploadTable;

class FileUploadsUtils
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
     * @param UploadTable $table Upload Table Instance
     */
    public function __construct(UploadTable $table)
    {
        $this->_table = $table;

        $this->_getFileStorageAssociationInstance();
        $this->_fileStorageForeignKey = 'foreign_key';
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
     * @param  string              $data  Record id
     * @return \Cake\ORM\ResultSet
     */
    public function getFiles($data)
    {
        $query = $this->_fileStorageAssociation->find('all', [
            'conditions' => [$this->_fileStorageForeignKey => $data]
        ]);

        return $query->all();
    }

    /**
     * File save method.
     *
     * @param  \Cake\ORM\Entity $entity Associated Entity
     * @param  array            $files  Uploaded files
     * @return bool
     */
    public function save(Entity $entity, array $files = [])
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

            $fsEntity = $this->_storeFileStorage($entity, ['file' => $file]);
            if ($fsEntity) {
                $result = $this->_storeFile($entity, $fsEntity, $file);
            }
        }

        return $result;
    }

    /**
     * Store to FileStorage table.
     *
     * @param  object $docEntity Document entity
     * @param  array $fileData File data
     * @return object|bool Fresh created entity or false on unsuccesful attempts.
     */
    protected function _storeFileStorage($docEntity, $fileData)
    {
        $fileStorEnt = $this->_fileStorageAssociation->newEntity($fileData);
        $fileStorEnt = $this->_fileStorageAssociation->patchEntity(
            $fileStorEnt,
            [$this->_fileStorageForeignKey => $docEntity->get('id')]
        );

        if ($this->_fileStorageAssociation->save($fileStorEnt)) {
            $this->createThumbnails($fileStorEnt);

            return $fileStorEnt;
        }

        return false;
    }

    /**
     * Store file entity.
     *
     * @param  object $docEntity Document entity
     * @param  object $fileStorEnt FileStorage entity
     * @return object|bool
     */
    protected function _storeFile($docEntity, $fileStorEnt)
    {
        $entity = $this->_fileAssociation->newEntity([
            $this->_documentForeignKey => $docEntity->get('id'),
            $this->_fileForeignKey => $fileStorEnt->get('id'),
        ]);

        return $this->_fileAssociation->save($entity);
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

        if ($result) {
            $result = $this->_deleteFileRecord($id);
        }

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
        if (is_null($this->_fileAssociation)) {
            return false;
        }

        $query = $this->_fileAssociation->find('all', [
            'conditions' => [$this->_fileForeignKey => $id]
        ]);
        $entity = $query->first();

        if (is_null($entity)) {
            return false;
        }

        return $this->_fileAssociation->delete($entity);
    }

    /**
     * Method that fetches and deletes file Entity.
     *
     * @param  string $id file id
     * @return bool
     */
    protected function _deleteFileRecord($id)
    {
        $entity = $this->_fileStorageAssociation->get($id);

        $result = $this->_fileStorageAssociation->delete($entity);

        if ($result) {
            $this->_removeThumbnails($entity);
        }

        return $result;
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

        $operations = Configure::read('FileStorage.imageSizes.' . static::TABLE_FILE_STORAGE);
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
