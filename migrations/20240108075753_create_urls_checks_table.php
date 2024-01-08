<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUrlsChecksTable extends AbstractMigration
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
        $table = $this->table('url_checks');

        $table->addColumn('url_id', 'biginteger', ['null' => true])
            ->addForeignKey('url_id', 'urls', 'id',
                ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addColumn('status_code', 'integer', ['null' => true])
            ->addColumn('h1', 'text', ['null' => true])
            ->addColumn('title', 'text', ['null' => true])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->create();
    }

    public function down(): void
    {
        $this->table('url_checks')->drop()->save();
    }
}
