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
namespace CsvMigrations\Shell;

use AuditStash\Meta\RequestMetadata;
use CakeDC\Users\Controller\Traits\CustomUsersTableTrait;
use Cake\Console\ConsoleOptionParser;
use Cake\Console\Shell;
use Cake\Core\Configure;
use Cake\Core\Exception\Exception as CakeException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\RepositoryInterface;
use Cake\Event\EventManager;
use Cake\Http\ServerRequest;
use Cake\I18n\Time;
use Cake\ORM\Entity;
use Cake\ORM\Exception\RolledbackTransactionException;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Shell\Helper\ProgressHelper;
use CsvMigrations\Model\Entity\Import;
use CsvMigrations\Model\Entity\ImportResult;
use CsvMigrations\Model\Table\ImportsTable;
use CsvMigrations\Utility\Field as FieldUtility;
use CsvMigrations\Utility\Import as ImportUtility;
use League\Csv\Reader;
use League\Csv\Writer;
use NinjaMutex\MutexException;
use PDOException;
use Qobo\Utils\Utility\Lock\FileLock;
use Qobo\Utils\Utility\User;
use RuntimeException;

class ImportShell extends Shell
{
    use CustomUsersTableTrait;

    /**
     * Set shell description and command line options
     *
     * @return ConsoleOptionParser
     */
    public function getOptionParser()
    {
        $parser = new ConsoleOptionParser('console');
        $parser->setDescription('Process all import jobs');

        return $parser;
    }

    /**
     * Main method for shell execution
     *
     * @return void
     */
    public function main() : void
    {
        try {
            $lock = new FileLock('import_' . md5(__FILE__) . '.lock');
        } catch (MutexException $e) {
            $this->warn($e->getMessage());

            return;
        }

        if (!$lock->lock()) {
            $this->warn('Import is already in progress');

            return;
        }

        /** @var \CsvMigrations\Model\Table\ImportsTable */
        $table = TableRegistry::get('CsvMigrations.Imports');
        $query = $table->find('all')
            ->where([
                'status IN' => [$table::STATUS_PENDING, $table::STATUS_IN_PROGRESS],
                'options IS NOT' => null,
                'options !=' => '',
            ]);

        foreach ($query->all() as $import) {
            // detach previous iteration listener
            if (isset($listener)) {
                EventManager::instance()->off($listener);
            }

            if (! $import->get('created_by')) {
                $this->warn('Skipping, "created_by" user is not set on this import.');
                continue;
            }

            // set current user to the one who uploaded the import (for footprint behavior)
            User::setCurrentUser(
                $this->getUsersTable()
                    ->get($import->get('created_by'))
                    ->toArray()
            );
            // for audit-stash functionality
            $listener = new RequestMetadata(new ServerRequest(), User::getCurrentUser()['id']);
            EventManager::instance()->on($listener);

            $path = ImportUtility::getProcessedFile($import);
            $filename = ImportUtility::getProcessedFile($import, false);

            $this->info(sprintf('Importing file "%s":', $filename));
            $this->hr();

            // process import file
            $this->processImportFile($import);

            if (empty($import->get('options'))) {
                $this->warn(sprintf('Skipping, no mapping found for "%s"', $filename));
                $this->out($this->nl(1));

                continue;
            }

            $count = ImportUtility::getRowsCount($path);

            // new import
            if ($table::STATUS_PENDING === $import->get('status')) {
                $this->_newImport($table, $import, $count);
            }

            // in progress import
            if ($table::STATUS_IN_PROGRESS === $import->get('status')) {
                $this->_existingImport($table, $import, $count);
            }
            $this->out($this->nl(1));
        }

        $this->success('Import(s) completed');

        // unlock file
        $lock->unlock();
    }

    /**
     * Process import file.
     *
     * @param \CsvMigrations\Model\Entity\Import $import Import entity
     * @return void
     */
    protected function processImportFile(Import $import) : void
    {
        $this->out('Processing import file ..');

        $path = ImportUtility::getProcessedFile($import);
        if (file_exists($path)) {
            return;
        }

        // create processed file
        $writer = Writer::createFromPath($path, 'w+');

        $reader = Reader::createFromPath($import->get('filename'), 'r');

        $results = $reader->fetch();
        foreach ($results as $row) {
            if (empty(array_filter($row))) {
                continue;
            }

            $writer->insertOne($row);
        }
    }

    /**
     * New import.
     *
     * @param \CsvMigrations\Model\Table\ImportsTable $table Import table instance
     * @param \CsvMigrations\Model\Entity\Import $import Import entity
     * @param int $count Progress count
     * @return bool
     */
    protected function _newImport(ImportsTable $table, Import $import, int $count) : bool
    {
        $data = [
            'status' => $table::STATUS_IN_PROGRESS,
            'attempts' => 1,
            'attempted_date' => Time::now()
        ];

        $import = $table->patchEntity($import, $data);
        $table->save($import);

        $this->_run($import, $count);

        // mark import as completed
        $data = [
            'status' => $table::STATUS_COMPLETED
        ];

        $import = $table->patchEntity($import, $data);

        return (bool)$table->save($import);
    }

    /**
     * Existing import.
     *
     * @param \CsvMigrations\Model\Table\ImportsTable $table Import table instance
     * @param \CsvMigrations\Model\Entity\Import $import Import entity
     * @param int $count Progress count
     * @return bool
     */
    protected function _existingImport(ImportsTable $table, Import $import, int $count) : bool
    {
        $result = false;

        $data = ['attempted_date' => Time::now()];

        // max attempts rearched
        if ($import->get('attempts') >= (int)Configure::read('Importer.max_attempts')) {
            // set import as failed
            $data['status'] = $table::STATUS_FAIL;
            $import = $table->patchEntity($import, $data);

            return (bool)$table->save($import);
        }

        // increase attempts count
        $data['attempts'] = $import->get('attempts') + 1;
        $import = $table->patchEntity($import, $data);
        $table->save($import);

        $this->_run($import, $count);

        // mark import as completed
        $data['status'] = $table::STATUS_COMPLETED;
        $import = $table->patchEntity($import, $data);

        return (bool)$table->save($import);
    }

    /**
     * Run data import.
     *
     * @param \CsvMigrations\Model\Entity\Import $import Import entity
     * @param int $count Progress count
     * @return void
     */
    protected function _run(Import $import, int $count) : void
    {
        // generate import results records
        $this->createImportResults($import, $count);

        $this->out('Importing records ..');
        /** @var \Cake\Shell\Helper\ProgressHelper */
        $progress = $this->helper('Progress');
        $progress->init();

        $headers = ImportUtility::getUploadHeaders($import);
        $filename = ImportUtility::getProcessedFile($import);
        $reader = Reader::createFromPath($filename, 'r');
        foreach ($reader as $index => $row) {
            // skip first csv row
            if (0 === $index) {
                continue;
            }

            // skip empty row
            if (empty($row)) {
                continue;
            }

            $this->_importResult($import, $headers, $index, $row);

            $progress->increment(100 / $count);
            $progress->draw();
        }

        $this->out(null);
    }

    /**
     * Import results generator.
     *
     * @param \CsvMigrations\Model\Entity\Import $import Import entity
     * @param int $count Progress count
     * @return void
     */
    protected function createImportResults(Import $import, int $count) : void
    {
        $this->out('Preparing records ..');

        /** @var \Cake\Shell\Helper\ProgressHelper */
        $progress = $this->helper('Progress');
        $progress->init();

        /** @var \CsvMigrations\Model\Table\ImportResultsTable */
        $table = TableRegistry::get('CsvMigrations.ImportResults');

        $query = $table->find('all')->where(['import_id' => $import->get('id')]);
        $queryCount = $query->count();

        if ($queryCount >= $count) {
            return;
        }

        $data = [
            'import_id' => $import->get('id'),
            'status' => $table::STATUS_PENDING,
            'status_message' => $table::STATUS_PENDING_MESSAGE,
            'model_name' => $import->get('model_name')
        ];

        $i = $queryCount + 1;
        $progressCount = $count - $queryCount;
        // set $i = 1 to skip header row
        for ($i; $i <= $count; $i++) {
            $data['row_number'] = $i;

            $entity = $table->newEntity();
            $entity = $table->patchEntity($entity, $data);

            $table->save($entity);

            $progress->increment(100 / $progressCount);
            $progress->draw();
        }

        $this->out(null);
    }

    /**
     * Import row.
     *
     * @param \CsvMigrations\Model\Entity\Import $import Import entity
     * @param string[] $headers Upload file headers
     * @param int $rowNumber Current row number
     * @param mixed[] $data Row data
     * @return void
     */
    protected function _importResult(Import $import, array $headers, int $rowNumber, array $data) : void
    {
        /** @var \CsvMigrations\Model\Table\ImportResultsTable */
        $importTable = TableRegistry::get('CsvMigrations.ImportResults');
        $query = $importTable->find('all')
            ->enableHydration(true)
            ->where(['import_id' => $import->get('id'), 'row_number' => $rowNumber]);

        /** @var \CsvMigrations\Model\Entity\ImportResult|null */
        $importResult = $query->first();
        if (null === $importResult) {
            return;
        }

        // skip successful imports
        if ($importTable::STATUS_SUCCESS === $importResult->get('status')) {
            return;
        }

        $table = TableRegistry::get($importResult->get('model_name'));

        $data = $this->_prepareData($import, $headers, $data);
        $csvFields = FieldUtility::getCsv($table);
        $data = $this->_processData($table, $csvFields, $data);

        // skip empty processed data
        if (empty($data)) {
            $this->_importFail($importResult, ['Row has no data']);

            return;
        }

        try {
            $entity = $table->newEntity();
            $entity = $table->patchEntity($entity, $data);

            $table->save($entity) ?
                $this->_importSuccess($importResult, $entity) :
                $this->_importFail($importResult, $entity->getErrors());
        } catch (CakeException $e) {
            $this->_importFail($importResult, [$e->getMessage()]);
        } catch (PDOException $e) {
            $this->_importFail($importResult, [$e->getMessage()]);
        }
    }

    /**
     * Prepare row data.
     *
     * @param \CsvMigrations\Model\Entity\Import $import Import entity
     * @param string[] $headers Upload file headers
     * @param mixed[] $data Row data
     * @return mixed[]
     */
    protected function _prepareData(Import $import, array $headers, array $data) : array
    {
        $result = [];

        $options = $import->get('options');

        $flipped = array_flip($headers);

        foreach ($options['fields'] as $field => $params) {
            if (empty($params['column']) && empty($params['default'])) {
                continue;
            }

            if (array_key_exists($params['column'], $flipped)) {
                $value = $data[$flipped[$params['column']]];
                if (!empty($value)) {
                    $result[$field] = $value;
                    continue;
                }
            }

            if (!empty($params['default'])) {
                $result[$field] = $params['default'];
            }
        }

        return $result;
    }

    /**
     * Process row data.
     *
     * @param \Cake\ORM\Table $table Table instance
     * @param mixed[] $csvFields Table csv fields
     * @param mixed[] $data Entity data
     * @return mixed[]
     */
    protected function _processData(Table $table, array $csvFields, array $data) : array
    {
        $schema = $table->getSchema();
        foreach ($data as $field => $value) {
            if (!empty($csvFields) && in_array($field, array_keys($csvFields))) {
                switch ($csvFields[$field]->getType()) {
                    case 'related':
                        $data[$field] = $this->_findRelatedRecord($table, $field, $value);
                        break;
                    case 'list':
                        $data[$field] = $this->_findListValue($table, $csvFields[$field]->getLimit(), $value);
                        break;
                }
            } else {
                if ('uuid' === $schema->columnType($field)) {
                    $data[$field] = $this->_findRelatedRecord($table, $field, $value);
                } else {
                    $data[$field] = $value;
                }
            }
        }

        return $data;
    }

    /**
     * Fetch related record id if found, otherwise return initial value.
     *
     * @param \Cake\ORM\Table $table Table instance
     * @param string $field Field name
     * @param string $value Field value
     * @return string
     */
    protected function _findRelatedRecord(Table $table, string $field, string $value) : string
    {
        $csvField = FieldUtility::getCsvField($table, $field);
        if (null !== $csvField && 'related' === $csvField->getType()) {
            $relatedTable = (string)$csvField->getLimit();
            $value = $this->_findRelatedRecord(
                TableRegistry::get($relatedTable),
                TableRegistry::get($relatedTable)->getDisplayField(),
                $value
            );
        }

        foreach ($table->associations() as $association) {
            if ($association->getForeignKey() !== $field) {
                continue;
            }

            $targetTable = $association->getTarget();

            $primaryKey = $targetTable->getPrimaryKey();
            if (! is_string($primaryKey)) {
                throw new RuntimeException('Primary key must be a string');
            }

            // combine lookup fields with primary key and display field
            $lookupFields = array_merge(
                FieldUtility::getLookup($targetTable),
                [$primaryKey, $targetTable->getDisplayField()]
            );

            // remove virtual/non-existing fields
            $lookupFields = array_intersect($lookupFields, $targetTable->getSchema()->columns());

            // alias lookup fields
            foreach ($lookupFields as $k => $v) {
                $lookupFields[$k] = $targetTable->aliasField($v);
            }

            // populate lookup field values
            $lookupValues = array_fill(0, count($lookupFields), $value);

            $query = $targetTable->find('all')
                ->enableHydration(true)
                ->select([$targetTable->aliasField($primaryKey)])
                ->where(['OR' => array_combine($lookupFields, $lookupValues)]);

            /** @var \Cake\Datasource\EntityInterface|null */
            $entity = $query->first();
            if (null === $entity) {
                continue;
            }

            return $entity->get($primaryKey);
        }

        return $value;
    }

    /**
     * Fetch list value.
     *
     * First will try to find if the row value matches one
     * of the list options.
     *
     * @param \Cake\Datasource\RepositoryInterface $table Table instance
     * @param string $listName List name
     * @param string $value Field value
     * @return string
     */
    protected function _findListValue(RepositoryInterface $table, string $listName, string $value) : string
    {
        $options = FieldUtility::getList(sprintf('%s.%s', $table->getAlias(), $listName), true);

        // check against list options values
        foreach ($options as $val => $params) {
            if ($val !== $value) {
                continue;
            }

            return $val;
        }

        // check against list options labels
        foreach ($options as $val => $params) {
            if ($params['label'] !== $value) {
                continue;
            }

            return $val;
        }

        return $value;
    }

    /**
     * Mark import result as failed.
     *
     * @param \CsvMigrations\Model\Entity\ImportResult $entity ImportResult entity
     * @param mixed[] $errors Fail errors
     * @return bool
     */
    protected function _importFail(ImportResult $entity, array $errors) : bool
    {
        /** @var \CsvMigrations\Model\Table\ImportResultsTable */
        $table = TableRegistry::get('CsvMigrations.ImportResults');

        $entity->set('status', $table::STATUS_FAIL);
        $message = sprintf($table::STATUS_FAIL_MESSAGE, json_encode($errors));
        $entity->set('status_message', $message);

        return (bool)$table->save($entity);
    }

    /**
     * Mark import result as successful.
     *
     * @param \CsvMigrations\Model\Entity\ImportResult $importResult ImportResult entity
     * @param \Cake\Datasource\EntityInterface $entity Newly created Entity
     * @return bool
     */
    protected function _importSuccess(ImportResult $importResult, EntityInterface $entity) : bool
    {
        /** @var \CsvMigrations\Model\Table\ImportResultsTable */
        $table = TableRegistry::get('CsvMigrations.ImportResults');

        $importResult->set('model_id', $entity->get('id'));
        $importResult->set('status', $table::STATUS_SUCCESS);
        $importResult->set('status_message', $table::STATUS_SUCCESS_MESSAGE);

        return (bool)$table->save($importResult);
    }
}
