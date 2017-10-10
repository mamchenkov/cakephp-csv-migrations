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

use CsvMigrations\CsvMigration;

class <%= $name %> extends CsvMigration
{
    /**
     * Change Method.
     *
     * More information on this method is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-change-method
     * @return void
     */
    public function change()
    {
        $table = $this->table('<%= $table%>');
        $table = $this->csv($table);

        if (!$this->hasTable('<%= $table%>')) {
            $table->create();
        } else {
            $table->update();
        }

        $joinedTables = $this->joins('<%= $table%>');
        if (!empty($joinedTables)) {
            foreach ($joinedTables as $joinedTable) {
                $joinedTable->create();
            }
        }
    }
}
