<?php
use Migrations\AbstractMigration;

class AlterDbLists extends AbstractMigration
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
        $table = $this->table('db_lists');
        $table->addIndex([
            'name',
        ], [
            'name' => 'UNIQUE_NAME',
            'unique' => true,
        ]);
        $table->update();
    }
}
