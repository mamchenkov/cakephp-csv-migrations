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
namespace CsvMigrations\FieldHandlers\Config;

/**
 * SublistConfig
 *
 * This class provides the predefined configuration
 * for Sublist field handlers.
 */
class SublistConfig extends FixedConfig
{
    /**
     * @var array $providers List of provider names and classes
     */
    protected $providers = [
        'combinedFields' => '\\CsvMigrations\\FieldHandlers\\Provider\\CombinedFields\\NullCombinedFields',
        'fieldValue' => '\\CsvMigrations\\FieldHandlers\\Provider\\FieldValue\\MixedFieldValue',
        'fieldToDb' => '\\CsvMigrations\\FieldHandlers\\Provider\\FieldToDb\\ListFieldToDb',
        'searchOperators' => '\\CsvMigrations\\FieldHandlers\\Provider\\SearchOperators\\ListSearchOperators',
        'searchOptions' => '\\CsvMigrations\\FieldHandlers\\Provider\\SearchOptions\\ListSearchOptions',
        'selectOptions' => '\\CsvMigrations\\FieldHandlers\\Provider\\SelectOptions\\ListSelectOptions',
        'inputRenderAs' => '\\CsvMigrations\\FieldHandlers\\Provider\\RenderInput\\SublistRenderer',
        'valueRenderAs' => '\\CsvMigrations\\FieldHandlers\\Provider\\RenderValue\\ListRenderer',
        'nameRenderAs' => '\\CsvMigrations\\FieldHandlers\\Provider\\RenderName\\DefaultRenderer',
    ];
}
