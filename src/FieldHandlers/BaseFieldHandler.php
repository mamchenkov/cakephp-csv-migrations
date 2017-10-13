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

use Cake\Core\App;
use Cake\Event\Event;
use Cake\Network\Request;
use Cake\ORM\Entity;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use CsvMigrations\Event\EventName;
use CsvMigrations\FieldHandlers\CsvField;
use CsvMigrations\FieldHandlers\DbField;
use CsvMigrations\FieldHandlers\FieldHandlerInterface;
use CsvMigrations\View\AppView;
use InvalidArgumentException;
use Qobo\Utils\ModuleConfig\ConfigType;
use Qobo\Utils\ModuleConfig\ModuleConfig;
use RuntimeException;

/**
 * BaseFieldHandler
 *
 * This class provides the fallback functionality that
 * is common to all field handlers.
 *
 * NOTE: Try to avoid inheriting from this class directly.
 *       Instead, use one of the more specific base classes.
 *
 * @abstract
 */
abstract class BaseFieldHandler implements FieldHandlerInterface
{
    /**
     * Default database field type
     */
    const DB_FIELD_TYPE = 'string';

    /**
     * Default HTML form field type
     */
    const INPUT_FIELD_TYPE = 'text';

    /**
     * Flag for rendering value as is
     */
    const RENDER_PLAIN_VALUE = 'plain';

    /**
     * Renderer to use
     */
    const RENDERER = 'string';

    /**
     * Table object
     *
     * @var \Cake\ORM\Table
     */
    public $table;

    /**
     * Field name
     *
     * @var string
     */
    public $field;

    /**
     * View instance
     *
     * @var \Cake\View\View
     */
    public $cakeView;

    /**
     * Default options
     *
     * @var array
     */
    public $defaultOptions = [];

    /**
     * Search operators
     *
     * @var array
     */
    public $searchOperators = [
        'contains' => [
            'label' => 'contains',
            'operator' => 'LIKE',
            'pattern' => '%{{value}}%',
            'emptyCriteria' => [
                'aggregator' => 'OR',
                'values' => ['IS NULL', '= ""']
            ]
        ],
        'not_contains' => [
            'label' => 'does not contain',
            'operator' => 'NOT LIKE',
            'pattern' => '%{{value}}%',
            'emptyCriteria' => [
                'aggregator' => 'AND',
                'values' => ['IS NOT NULL', '!= ""']
            ]
        ],
        'starts_with' => [
            'label' => 'starts with',
            'operator' => 'LIKE',
            'pattern' => '{{value}}%',
            'emptyCriteria' => [
                'aggregator' => 'AND',
                'values' => ['IS NOT NULL', '!= ""']
            ]
        ],
        'ends_with' => [
            'label' => 'ends with',
            'operator' => 'LIKE',
            'pattern' => '%{{value}}',
            'emptyCriteria' => [
                'aggregator' => 'AND',
                'values' => ['IS NOT NULL', '!= ""']
            ]
        ],
    ];

    /**
     * Custom form input templates.
     *
     * @var array Associative array of templates
     */
    protected $_templates = [
        'input' => '<div class="input-group %s">
            <div class="input-group-addon">
                <i class="fa fa-%s"></i>
            </div>
            <input type="{{type}}" name="{{name}}"{{attrs}}/>
        </div>'
    ];

    /**
     * Constructor
     *
     * @param mixed  $table    Name or instance of the Table
     * @param string $field    Field name
     * @param object $cakeView Optional instance of the AppView
     */
    public function __construct($table, $field, $cakeView = null)
    {
        $this->setTable($table);
        $this->setField($field);
        $this->setView($cakeView);
        $this->setDefaultOptions();
    }

    /**
     * Set table
     *
     * @throws \InvalidArgumentException when table is empty
     * @param mixed $table Table name of instance
     * @return void
     */
    protected function setTable($table)
    {
        if (empty($table)) {
            throw new InvalidArgumentException('Table cannot be empty.');
        }
        if (is_string($table)) {
            $table = TableRegistry::get($table);
        }
        $this->table = $table;
    }

    /**
     * Set field
     *
     * @throws \InvalidArgumentException when field is empty
     * @param string $field Field name
     * @return void
     */
    protected function setField($field)
    {
        $field = (string)$field;
        if (empty($field)) {
            throw new InvalidArgumentException('Field cannot be empty.');
        }
        $this->field = $field;
    }

    /**
     * Set default options
     *
     * Populate the $defaultOptions to make sure we always have
     * the fieldDefinitions options for the current field.
     *
     * @return void
     */
    protected function setDefaultOptions()
    {
        $this->setDefaultFieldOptions();
        $this->setDefaultFieldDefinitionOptions();
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
    protected function setDefaultFieldOptions()
    {
        $mc = new ModuleConfig(ConfigType::FIELDS(), Inflector::camelize($this->table->table()));
        $config = (array)json_decode(json_encode($mc->parse()), true);
        if (!empty($config[$this->field])) {
            $this->defaultOptions = array_replace_recursive($this->defaultOptions, $config[$this->field]);
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
    protected function setDefaultLabel()
    {
        if (!empty($this->defaultOptions['label'])) {
            return;
        }

        $text = $this->field;
        // Borrowed from FormHelper::label()
        if (substr($text, -5) === '._ids') {
            $text = substr($text, 0, -5);
        }
        if (strpos($text, '.') !== false) {
            $fieldElements = explode('.', $text);
            $text = array_pop($fieldElements);
        }
        if (substr($text, -3) === '_id') {
            $text = substr($text, 0, -3);
        }
        $text = __(Inflector::humanize(Inflector::underscore($text)));
        $this->defaultOptions['label'] = $text;
    }

    /**
     * Set default field definitions
     *
     * @return void
     */
    protected function setDefaultFieldDefinitionOptions()
    {
        // set $options['fieldDefinitions']
        $stubFields = [
            $this->field => [
                'name' => $this->field,
                'type' => self::DB_FIELD_TYPE, // not static:: to preserve string
            ],
        ];
        if (method_exists($this->table, 'getFieldsDefinitions') && is_callable([$this->table, 'getFieldsDefinitions'])) {
            $fieldDefinitions = $this->table->getFieldsDefinitions($stubFields);
            $this->defaultOptions['fieldDefinitions'] = new CsvField($fieldDefinitions[$this->field]);
        }

        // This should never be the case, except, maybe
        // for some unit test runs or custom non-CSV
        // modules.
        if (empty($this->defaultOptions['fieldDefinitions'])) {
            $this->defaultOptions['fieldDefinitions'] = new CsvField($stubFields[$this->field]);
        }
    }

    /**
     * Set default field value
     *
     * @return void
     */
    protected function setDefaultValue()
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
        $this->cakeView->eventManager()->dispatch($event);

        // Only overwrite the default if any events were triggered
        $listeners = $this->cakeView->eventManager()->listeners($eventName);
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
     * @param array  $options Options to fix
     * @return array          Fixed options
     */
    protected function fixOptions(array $options = [])
    {
        $result = $options;
        if (empty($result)) {
            return $result;
        }

        // Previously, fieldDefinitions could be either an array or a CsvField instance.
        // Now we expect it to always be a CsvField instance.  So, if we have a non-empty
        // array, then instantiate CsvField with the values from it.
        if (!empty($result['fieldDefinitions']) && is_array($result['fieldDefinitions'])) {
            // Sometimes, when setting fieldDefinitions manually to render a particular
            // type, the name is omitted.  This works for an array, but doesn't work for
            // the CsvField instance, as the name is required.  Gladly, we know the name
            // and can fix it easily.
            if (empty($result['fieldDefinitions']['name'])) {
                $result['fieldDefinitions']['name'] = $this->field;
            }
            $result['fieldDefinitions'] = new CsvField($result['fieldDefinitions']);
        }

        return $result;
    }

    /**
     * Set view
     *
     * If an instance of the view is given, use that.
     * Otherwise, instantiate a new view.
     *
     * @param object $view View
     * @return void
     */
    protected function setView($view = null)
    {
        if ($view) {
            $this->cakeView = $view;
        } else {
            $this->cakeView = new AppView();
        }
    }

    /**
     * Render field input
     *
     * This method prepares the form input for the given field,
     * including the input itself, label, pre-populated value,
     * and so on.  The result can be controlled via the variety
     * of options.
     *
     * @param  string $data    Field data
     * @param  array  $options Field options
     * @return string          Field input HTML
     */
    public function renderInput($data = '', array $options = [])
    {
        $options = array_merge($this->defaultOptions, $this->fixOptions($options));
        $data = (string)$this->_getFieldValueFromData($data);
        if (empty($data) && !empty($options['default'])) {
            $data = $options['default'];
        }

        $fieldName = $this->table->aliasField($this->field);

        $params = [
            'field' => $this->field,
            'name' => $fieldName,
            'type' => static::INPUT_FIELD_TYPE,
            'label' => $options['label'],
            'required' => $options['fieldDefinitions']->getRequired(),
            'value' => $data,
            'extraClasses' => (!empty($options['extraClasses']) ? implode(' ', $options['extraClasses']) : ''),
        ];

        return $this->_renderElement(__FUNCTION__, $params, $options);
    }

    /**
     * Render Field Handler element
     *
     * Handles logic for rendering appropriate element based on Field Handler
     * class and render method (renderInput, renderValue etc).
     *
     * Supports rendering custom element by passing the element's name using
     * $options['element'] parameter. If the element does exist, it will be used,
     * and the Field Handler appropriate parameters will be passed to it.
     *
     * If a custom element was not provided, then it will try and use the specific
     * Field Handler's render element. If there is no specific render element for
     * the Field Handler, it will use the Base Field Handler element. If that does
     * not exist either, then an exception will be thrown.
     *
     * @param string $method Method name (example: renderInput)
     * @param array $params Element parameters
     * @param array $options Field options
     * @throws \RuntimeException If no element was found
     * @return string
     */
    protected function _renderElement($method, array $params, array $options = [])
    {
        $params += ['attributes' => empty($options['attributes']) ? [] : $options['attributes']];

        // render custom element
        if (!empty($options['element']) && $this->cakeView->elementExists($options['element'])) {
            return $this->cakeView->element($options['element'], $params);
        }

        $type = strtolower($method);
        $type = str_replace('render', '', $type);

        $fqcn = get_class($this);
        $className = substr($fqcn, strrpos($fqcn, '\\') + 1);

        $element = 'CsvMigrations.FieldHandlers/' . $className . '/' . $type;

        // if element does not exist, use default one
        if (!$this->cakeView->elementExists($element)) {
            $element = 'CsvMigrations.FieldHandlers/BaseFieldHandler/' . $type;
        }

        // if no element was found, throw exception
        if (!$this->cakeView->elementExists($element)) {
            throw new RuntimeException(
                Inflector::humanize($type) . ' element, for class ' . $className . ', was not found.'
            );
        }

        return $this->cakeView->element($element, $params);
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
    public function getSearchOptions(array $options = [])
    {
        $result = [];

        $options = array_merge($this->defaultOptions, $this->fixOptions($options));

        if ($options['fieldDefinitions']->getNonSearchable()) {
            return $result;
        }

        $content = $this->cakeView->Form->input('{{name}}', [
            'value' => '{{value}}',
            'type' => static::INPUT_FIELD_TYPE,
            'label' => false
        ]);

        $result[$this->field] = [
            'type' => $options['fieldDefinitions']->getType(),
            'label' => $this->renderName(),
            'operators' => $this->searchOperators,
            'input' => [
                'content' => $content,
            ],
        ];

        return $result;
    }

    /**
     * Render field name
     *
     * @return string
     */
    public function renderName()
    {
        return $this->defaultOptions['label'];
    }

    /**
     * Render field value
     *
     * This method prepares the output of the value for the given
     * field.  The result can be controlled via the variety of
     * options.
     *
     * @param  string $data    Field data
     * @param  array  $options Field options
     * @return string          Field value
     */
    public function renderValue($data, array $options = [])
    {
        $options = array_merge($this->defaultOptions, $this->fixOptions($options));
        $result = $this->_getFieldValueFromData($data);

        // Currently needed for blobs from the database, but might be handy later
        // for network data and such.
        // TODO: Add support for encoding (base64, et) via $options
        if (is_resource($result)) {
            $result = stream_get_contents($result);
        }

        $renderer = static::RENDERER;
        if (!empty($options['renderAs'])) {
            $renderer = $options['renderAs'];
        }

        $rendererClass = __NAMESPACE__ . '\\Renderer\\' . ucfirst($renderer) . 'Renderer';
        if (!class_exists($rendererClass)) {
            throw new InvalidArgumentException("Renderer [$renderer] is not supporter");
        }

        $rendererClass = new $rendererClass($this->cakeView);
        $result = (string)$rendererClass->renderValue($result, $options);

        if ($renderer === static::RENDER_PLAIN_VALUE) {
            return $result;
        }

        return $result;
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
    public static function fieldToDb(CsvField $csvField)
    {
        $csvField->setType(static::DB_FIELD_TYPE);

        $dbField = DbField::fromCsvField($csvField);
        $result = [
            $csvField->getName() => $dbField,
        ];

        return $result;
    }

    /**
     * Get field value from given data
     *
     * Extract field value from the variable, based on the type
     * of the variable.  Support types are:
     *
     * * Entity, use Entity property with the field name
     * * Request, use Request->data() with the key of the field name
     * * Otherwise assume the variable is the data already
     *
     * @param Entity|Request|mixed $data  Variable to extract value from
     * @param string               $field Optional field name
     * @return mixed
     */
    protected function _getFieldValueFromData($data, $field = null)
    {
        if (empty($field)) {
            $field = $this->field;
        }

        // Use data as is
        $result = $data;

        // Use $data->$field if available as Entity
        if ($data instanceof Entity) {
            $result = null;
            if (isset($data->$field)) {
                $result = $data->$field;
            }

            return $result;
        }

        // Use $data->data[$field] if available as Request
        if ($data instanceof Request) {
            $result = null;
            if (is_array($data->data) && array_key_exists($field, $data->data)) {
                $result = $data->data[$field];
            }

            return $result;
        }

        return $result;
    }
}
