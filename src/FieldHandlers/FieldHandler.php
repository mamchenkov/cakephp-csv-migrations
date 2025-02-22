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

namespace CsvMigrations\FieldHandlers;

use Cake\Event\Event;
use Cake\Utility\Inflector;
use Cake\Validation\Validator;
use CsvMigrations\Event\EventName;
use CsvMigrations\FieldHandlers\Config\ConfigFactory;
use CsvMigrations\FieldHandlers\Config\ConfigInterface;
use CsvMigrations\HasFieldsInterface;
use InvalidArgumentException;
use Qobo\Utils\ModuleConfig\ConfigType;
use Qobo\Utils\ModuleConfig\ModuleConfig;
use RuntimeException;

/**
 * FieldHandler
 *
 * This class provides field handler functionality.
 */
class FieldHandler implements FieldHandlerInterface
{
    /**
     * Default options
     *
     * @var array
     */
    public $defaultOptions = [];

    /**
     * @var \CsvMigrations\FieldHandlers\Config\ConfigInterface
     */
    protected $config;

    /**
     * Constructor
     *
     * @param \CsvMigrations\FieldHandlers\Config\ConfigInterface $config Instance of field handler config
     */
    public function __construct(ConfigInterface $config)
    {
        $this->setConfig($config);
        $this->setDefaultOptions();
    }

    /**
     * Config instance getter
     *
     * @return \CsvMigrations\FieldHandlers\Config\ConfigInterface
     */
    public function getConfig(): ConfigInterface
    {
        return $this->config;
    }

    /**
     * Config instance setter
     *
     * @param \CsvMigrations\FieldHandlers\Config\ConfigInterface $config Instance of field handler config
     * @return void
     */
    public function setConfig(ConfigInterface $config): void
    {
        $this->config = $config;
    }

    /**
     * Set default options
     *
     * Populate the $defaultOptions to make sure we always have
     * the fieldDefinitions options for the current field.
     *
     * @return void
     */
    protected function setDefaultOptions(): void
    {
        $this->setDefaultFieldOptions();
        $this->setDefaultFieldDefinitions();
        $this->setDefaultLabel();
        $this->setDefaultValue();
    }

    /**
     * Set default field options from config
     *
     * Read fields.ini configuration file and if there are any
     * options defined for the current field, use them as defaults.
     *
     * @return void
     */
    protected function setDefaultFieldOptions(): void
    {
        $table = $this->config->getTable();
        $field = $this->config->getField();

        $mc = new ModuleConfig(ConfigType::FIELDS(), Inflector::camelize($table->getTable()));
        $config = $mc->parseToArray();
        if (! empty($config[$field])) {
            $this->defaultOptions = (array)array_replace_recursive($this->defaultOptions, $config[$field]);
        }
    }

    /**
     * Set default field label
     *
     * NOTE: This should only be called AFTER the setDefaultFieldOptions()
     *       which reads fields.ini values, which might include the label
     *       option.
     *
     * @return void
     */
    protected function setDefaultLabel(): void
    {
        $this->defaultOptions['label'] = $this->renderName();
    }

    /**
     * Set default field definitions
     *
     * @return void
     */
    protected function setDefaultFieldDefinitions(): void
    {
        $table = $this->config->getTable();
        $field = $this->config->getField();
        $dbFieldType = $this->getDbFieldType();

        // set $options['fieldDefinitions']
        $stubFields = [
            $field => [
                'name' => $field,
                'type' => $dbFieldType,
            ],
        ];
        if ($table instanceof HasFieldsInterface) {
            $fieldDefinitions = $table->getFieldsDefinitions($stubFields);
            $this->defaultOptions['fieldDefinitions'] = new CsvField($fieldDefinitions[$field]);
        }

        // This should never be the case, except, maybe
        // for some unit test runs or custom non-CSV
        // modules.
        if (empty($this->defaultOptions['fieldDefinitions'])) {
            $this->defaultOptions['fieldDefinitions'] = new CsvField($stubFields[$field]);
        }
    }

    /**
     * Set default field value
     *
     * @return void
     */
    protected function setDefaultValue(): void
    {
        if (empty($this->defaultOptions['default'])) {
            return;
        }

        // If we have a default value from configuration, pass it through
        // processing for magic/dynamic values like dates and usernames.
        $eventName = (string)EventName::FIELD_HANDLER_DEFAULT_VALUE();
        $event = new Event($eventName, $this, [
            'default' => $this->defaultOptions['default']
        ]);

        $view = $this->config->getView();
        $view->getEventManager()->dispatch($event);

        // Only overwrite the default if any events were triggered
        $listeners = $view->getEventManager()->listeners($eventName);
        if (empty($listeners)) {
            return;
        }
        $this->defaultOptions['default'] = $event->result;
    }

    /**
     * Fix provided options
     *
     * This method is here to fix some issues with backward
     * compatibility and make sure that $options parameters
     * are consistent throughout.
     *
     * @param mixed[] $options Options to fix
     * @return mixed[] Fixed options
     */
    protected function fixOptions(array $options = []): array
    {
        $result = $options;
        if (empty($result)) {
            return $result;
        }

        if (empty($result['fieldDefinitions'])) {
            return $result;
        }

        if (!is_array($result['fieldDefinitions'])) {
            return $result;
        }

        // Sometimes, when setting fieldDefinitions manually to render a particular
        // type, the name is omitted.  This works for an array, but doesn't work for
        // the CsvField instance, as the name is required.  Gladly, we know the name
        // and can fix it easily.
        if (empty($result['fieldDefinitions']['name'])) {
            $result['fieldDefinitions']['name'] = $this->config->getField();
        }

        // Previously, fieldDefinitions could be either an array or a CsvField instance.
        // Now we expect it to always be a CsvField instance.  So, if we have a non-empty
        // array, then instantiate CsvField with the values from it.
        $result['fieldDefinitions'] = new CsvField($result['fieldDefinitions']);

        return $result;
    }

    /**
     * Render field input
     *
     * This method prepares the form input for the given field,
     * including the input itself, label, pre-populated value,
     * and so on.  The result can be controlled via the variety
     * of options.
     *
     * @param  mixed $data    Field data
     * @param  array  $options Field options
     * @return string          Field input HTML
     */
    public function renderInput($data = '', array $options = []): string
    {
        $options = array_merge($this->defaultOptions, $this->fixOptions($options));

        $data = $this->getFieldValueFromData($data, $options);
        // Workaround for BLOBs
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        if (empty($data) && !empty($options['default'])) {
            $data = $options['default'];
        }

        $options['label'] = !isset($options['label']) ? $this->renderName() : $options['label'];

        $searchOptions = $this->config->getProvider('renderInput');
        $searchOptions = new $searchOptions($this->config);
        $result = $searchOptions->provide($data, $options);

        return $result;
    }

    /**
     * Get options for field search
     *
     * This method prepares an array of search options, which includes
     * label, form input, supported search operators, etc.  The result
     * can be controlled with a variety of options.
     *
     * @param  array  $options Field options
     * @return array           Array of field input HTML, pre and post CSS, JS, etc
     */
    public function getSearchOptions(array $options = []): array
    {
        $result = [];

        $options = array_merge($this->defaultOptions, $this->fixOptions($options));

        if ($options['fieldDefinitions']->getNonSearchable()) {
            return $result;
        }

        $options['label'] = empty($options['label']) ? $this->renderName() : $options['label'];

        $searchOptions = $this->config->getProvider('searchOptions');
        $searchOptions = new $searchOptions($this->config);
        $result = $searchOptions->provide(null, $options);

        return $result;
    }

    /**
     * Render field name
     *
     * @return string
     */
    public function renderName(): string
    {
        $label = !empty($this->defaultOptions['label']) ? $this->defaultOptions['label'] : '';

        $renderer = $this->config->getProvider('renderName');
        $renderer = new $renderer($this->config);
        $result = $renderer->provide($label);

        return $result;
    }

    /**
     * Render field value
     *
     * This method prepares the output of the value for the given
     * field.  The result can be controlled via the variety of
     * options.
     *
     * @param  mixed $data    Field data
     * @param  array  $options Field options
     * @return string          Field value
     */
    public function renderValue($data, array $options = []): string
    {
        $options = array_merge($this->defaultOptions, $this->fixOptions($options));
        $result = $this->getFieldValueFromData($data, $options);

        // Currently needed for blobs from the database, but might be handy later
        // for network data and such.
        // TODO: Add support for encoding (base64, et) via $options
        if (is_resource($result)) {
            $result = stream_get_contents($result);
        }

        $rendererClass = $this->config->getProvider('renderValue');
        if (!empty($options['renderAs'])) {
            $rendererClass = __NAMESPACE__ . '\\Provider\\RenderValue\\' . ucfirst($options['renderAs']) . 'Renderer';
        }

        if (!class_exists($rendererClass)) {
            throw new InvalidArgumentException("Renderer class [$rendererClass] does not exist");
        }

        $rendererClass = new $rendererClass($this->config);
        $result = (string)$rendererClass->provide($result, $options);

        return $result;
    }

    /**
     * Validation rules setter.
     *
     * @param \Cake\Validation\Validator $validator Validator instance
     * @param array $options Field options
     * @return \Cake\Validation\Validator
     */
    public function setValidationRules(Validator $validator, array $options = []): Validator
    {
        $options = array_merge($this->defaultOptions, $this->fixOptions($options));

        $provider = $this->config->getProvider('validationRules');
        $validator = (new $provider($this->config))->provide($validator, $options);
        if (! $validator instanceof Validator) {
            throw new RuntimeException(
                sprintf('Provider returned value must be an instance of %s.', Validator::class)
            );
        }

        return $validator;
    }

    /**
     * Convert CsvField to one or more DbField instances
     *
     * Simple fields from migrations CSV map one-to-one to
     * the database fields.  More complex fields can combine
     * multiple database fields for a single CSV entry.
     *
     * @param  \CsvMigrations\FieldHandlers\CsvField $csvField CsvField instance
     * @return array                                           DbField instances
     */
    public static function fieldToDb(CsvField $csvField): array
    {
        $config = ConfigFactory::getByType($csvField->getType(), $csvField->getName());
        $fieldToDb = $config->getProvider('fieldToDb');
        $fieldToDb = new $fieldToDb($config);
        $result = $fieldToDb->provide($csvField);

        return $result;
    }

    /**
     * Get database field type
     *
     * @return string
     */
    public function getDbFieldType(): string
    {
        $dbFieldType = $this->config->getProvider('dbFieldType');
        $dbFieldType = new $dbFieldType($this->config);
        $dbFieldType = $dbFieldType->provide();

        return $dbFieldType;
    }

    /**
     * Get field value from given data
     *
     * @param mixed $data Variable to extract value from
     * @param mixed[] $options Field options
     * @return mixed
     */
    protected function getFieldValueFromData($data, array $options)
    {
        $fieldValue = $this->config->getProvider('fieldValue');
        $fieldValue = new $fieldValue($this->config);
        $result = $fieldValue->provide($data, $options);

        return $result;
    }
}
