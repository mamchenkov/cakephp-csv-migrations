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

$attributes = isset($attributes) ? $attributes : [];

$attributes += [
    'type' => 'text',
    'label' => $label,
    'data-provide' => 'datetimepicker',
    'autocomplete' => 'off',
    'required' => (bool)$required,
    'value' => $value,
    'templates' => [
        'input' => '<div class="input-group">
            <div class="input-group-addon">
                <i class="fa fa-calendar"></i>
            </div>
            <input type="{{type}}" name="{{name}}"{{attrs}}/>
        </div>'
    ]
];

if (isset($timePicker)) {
    $attributes += ['data-time-picker' => ($timePicker ? 'true' : 'false')];
}

if (!empty($timeIncrement)) {
    $attributes += ['data-time-picker-increment' => $timeIncrement];
}

if (isset($showMonthYearSelect)) {
    $attributes += ['data-show-dropdowns' => ($showMonthYearSelect ? 'true' : 'false')];
}

echo $this->Form->control($name, $attributes);
