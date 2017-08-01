<?php
namespace CsvMigrations\Utility;

use Cake\Controller\Component\FlashComponent;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use Cake\View\View;
use CsvMigrations\Model\Entity\Import as ImportEntity;
use CsvMigrations\Model\Table\ImportsTable;
use League\Csv\Reader;
use Qobo\Utils\ModuleConfig\ModuleConfig;

class Import
{
    private $__supportedExtensions = [
        'text/csv'
    ];

    private $__supportedTypes = [
        'string',
        'email',
        'text',
        'url',
        'reminder',
        'datetime',
        'date',
        'time'
    ];

    /**
     * Constructor method.
     *
     * @param \Cake\Http\ServerRequest $request Request instance
     * @param \Cake\Controller\Component\FlashComponent $flash Flash component
     */
    public function __construct(ServerRequest $request, FlashComponent $flash)
    {
        $this->_request = $request;
        $this->_flash = $flash;
    }

    /**
     * Import file upload logic.
     *
     * @return string
     */
    public function upload()
    {
        if (!$this->_validateUpload()) {
            return '';
        }

        return $this->_uploadFile();
    }

    /**
     * Create import record.
     *
     * @param \CsvMigrations\Model\Table\ImportsTable $table Table instance
     * @param \CsvMigrations\Model\Entity\Import $entity Import entity
     * @param string $filename Uploaded file name
     * @return bool
     */
    public function create(ImportsTable $table, ImportEntity $entity, $filename)
    {
        $modelName = $this->_request->getParam('controller');
        if ($this->_request->getParam('plugin')) {
            $modelName = $this->_request->getParam('plugin') . '.' . $modelName;
        }

        $data = [
            'filename' => $filename,
            'status' => $table->getStatusPending(),
            'model_name' => $modelName,
            'attempts' => 0
        ];

        $entity = $table->patchEntity($entity, $data);

        return $table->save($entity);
    }

    /**
     * Map import file columns to database columns.
     *
     * @param \CsvMigrations\Model\Table\ImportsTable $table Table instance
     * @param \CsvMigrations\Model\Entity\Import $entity Import entity
     * @return bool
     */
    public function mapColumns(ImportsTable $table, ImportEntity $entity)
    {
        $entity = $table->patchEntity($entity, ['options' => $this->_request->data('options')]);

        return $table->save($entity);
    }

    /**
     * Import results getter.
     *
     * @param \CsvMigrations\Model\Entity\Import $entity Import entity
     * @param array $columns Display columns
     * @return \Cake\ORM\Query
     */
    public function getImportResults(ImportEntity $entity, array $columns)
    {
        $sortCol = $this->_request->query('order.0.column') ?: 0;
        $sortCol = array_key_exists($sortCol, $columns) ? $columns[$sortCol] : current($columns);

        $sortDir = $this->_request->query('order.0.dir') ?: 'asc';
        if (!in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'asc';
        }

        $table = TableRegistry::get('CsvMigrations.ImportResults');

        $query = $table->find('all')
            ->where([$table->aliasField('import_id') => $entity->id])
            ->order([$table->aliasField($sortCol) => $sortDir]);

        return $query;
    }

    /**
     * Import results setter.
     *
     * @param \CsvMigrations\Model\Entity\Import $import Import entity
     * @return void
     */
    public function setImportResults(ImportEntity $import)
    {
        $count = $this->_getRowsCount($import);

        if (0 >= $count) {
            return;
        }

        $table = TableRegistry::get('CsvMigrations.ImportResults');

        $modelName = $this->_request->getParam('controller');
        if ($this->_request->getParam('plugin')) {
            $modelName = $this->_request->getParam('plugin') . '.' . $modelName;
        }

        $data = [
            'import_id' => $import->id,
            'status' => $table->getStatusPending(),
            'status_message' => $table->getStatusPendingMessage(),
            'model_name' => $modelName
        ];

        // set $i = 1 to skip header row
        for ($i = 1; $i < $count; $i++) {
            $data['row_number'] = $i;

            $entity = $table->newEntity();
            $entity = $table->patchEntity($entity, $data);

            $table->save($entity);
        }
    }

    /**
     * Get CSV file rows count.
     *
     * @param \CsvMigrations\Model\Entity\Import $entity Import entity
     * @return int
     */
    protected function _getRowsCount(ImportEntity $entity)
    {
        $reader = Reader::createFromPath($entity->filename, 'r');

        $result = $reader->each(function ($row) {
            return true;
        });

        return (int)$result;
    }

    /**
     * Get upload file column headers (first row).
     *
     * @param \CsvMigrations\Model\Entity\Import $entity Import entity
     * @return array
     */
    public static function getUploadHeaders(ImportEntity $entity)
    {
        $reader = Reader::createFromPath($entity->filename, 'r');

        $result = $reader->fetchOne();

        foreach ($result as $k => $v) {
            $v = str_replace(' ', '', trim($v));
            $result[$k] = Inflector::underscore($v);
        }

        return $result;
    }

    /**
     * Get target module fields.
     *
     * @return array
     */
    public function getModuleFields()
    {
        $mc = new ModuleConfig(ModuleConfig::CONFIG_TYPE_MIGRATION, $this->_request->getParam('controller'));

        $result = [];
        foreach ($mc->parse() as $field) {
            if (!in_array($field->type, $this->__supportedTypes)) {
                continue;
            }

            $result[$field->name] = $field->name;
        }

        return $result;
    }

    /**
     * Method that re-formats entities to Datatables supported format.
     *
     * @param \Cake\ORM\ResultSet $resultSet ResultSet
     * @param array $fields Display fields
     * @param \Cake\ORM\Table $table Table instance
     * @return array
     */
    public function toDatatables(ResultSet $resultSet, array $fields, Table $table)
    {
        $result = [];

        if ($resultSet->isEmpty()) {
            return $result;
        }

        $view = new View();
        $plugin = $this->_request->getParam('plugin');
        $controller = $this->_request->getParam('controller');

        foreach ($resultSet as $key => $entity) {
            foreach ($fields as $field) {
                $result[$key][] = $entity->get($field);
            }

            $viewButton = '';
            // set view button if model id is set
            if ($entity->get('model_id')) {
                $url = [
                    'prefix' => false,
                    'plugin' => $plugin,
                    'controller' => $controller,
                    'action' => 'view',
                    $entity->model_id
                ];
                $link = $view->Html->link('<i class="fa fa-eye"></i>', $url, [
                    'title' => __('View'),
                    'class' => 'btn btn-default',
                    'escape' => false
                ]);

                $viewButton = '<div class="btn-group btn-group-xs" role="group">' . $link . '</div>';
            }

            $result[$key][] = $viewButton;
        }

        return $result;
    }

    /**
     * Upload file validation.
     *
     * @return bool
     */
    protected function _validateUpload()
    {
        if (!$this->_request->data('file')) {
            $this->_flash->error(__('Please choose a file to upload.'));

            return false;
        }

        if (!in_array($this->_request->data('file.type'), $this->__supportedExtensions)) {
            $this->_flash->error(__('Unable to upload file, unsupported file provided.'));

            return false;
        }

        return true;
    }

    /**
     * Upload data file.
     *
     * @return string
     */
    protected function _uploadFile()
    {
        $uploadPath = $this->_getUploadPath();

        if (empty($uploadPath)) {
            return '';
        }

        $uploadPath .= $this->_request->data('file.name');

        if (!move_uploaded_file($this->_request->data('file.tmp_name'), $uploadPath)) {
            $this->_flash->error(__('Unable to upload file to the specified directory.'));

            return '';
        }

        return $uploadPath;
    }

    /**
     * Upload path getter.
     *
     * @return string
     */
    protected function _getUploadPath()
    {
        $result = Configure::read('Importer.path');

        // if no path specified, fallback to the default.
        if (!$result) {
            $result = WWW_ROOT . 'uploads' . DS;
        }

        // include trailing directory separator.
        $result = rtrim($result, DS);
        $result .= DS;

        if (file_exists($result)) {
            return $result;
        }

        // create upload path, recursively.
        if (!mkdir($result, 0777, true)) {
            $this->_flash->error(__('Failed to create upload directory.'));

            return '';
        }

        return $result;
    }
}
