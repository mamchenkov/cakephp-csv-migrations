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

namespace CsvMigrations\FieldHandlers\Provider\RenderValue;

use Cake\ORM\TableRegistry;
use CsvMigrations\Aggregator\AggregateResult;
use CsvMigrations\Aggregator\Configuration;
use CsvMigrations\FieldHandlers\FieldHandlerFactory;

/**
 * AggregatedRenderer
 *
 * Aggregated value renderer
 */
class AggregatedRenderer extends AbstractRenderer
{
    /**
     * Provide rendered value
     *
     * @param mixed $data Data to use for provision
     * @param array $options Options to use for provision
     * @return mixed
     */
    public function provide($data = null, array $options = [])
    {
        $config = explode(',', $options['fieldDefinitions']->getLimit(), 4);

        $configuration = new Configuration(TableRegistry::get($config[1]), $config[2]);
        $configuration->setJoinData($this->config->getTable(), $options['entity'])
            ->setDisplayField(isset($config[3]) ? $config[3] : '');

        $aggregator = new $config[0]($configuration);

        $factory = new FieldHandlerFactory($this->config->getView());

        return $factory->renderValue(
            $aggregator->getConfig()->getTable(),
            $aggregator->getConfig()->getDisplayField(),
            AggregateResult::get($aggregator),
            ['entity' => $options['entity']]
        );
    }
}
