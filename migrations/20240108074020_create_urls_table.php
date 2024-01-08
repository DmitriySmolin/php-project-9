<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUrlsTable extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function up(): void
    {
        $table = $this->table('urls');
        $table->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('created_at', 'timestamp')
            ->create();
    }

    public function down(): void
    {
        $this->table('urls')->drop()->save();
    }
}
