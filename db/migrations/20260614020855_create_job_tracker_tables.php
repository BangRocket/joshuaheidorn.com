<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateJobTrackerTables extends AbstractMigration
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
    public function change(): void
    {
        $this->table('jobs')
            ->addColumn('company', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('role', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('link', 'string', ['limit' => 1024, 'default' => ''])
            ->addColumn('salary', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('location', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('added', 'date', ['null' => false])
            ->addColumn('updated', 'date', ['null' => false])
            ->addIndex(['status'])
            ->addIndex(['updated'])
            ->create();

        $this->table('job_settings')
            ->addColumn('stale_days', 'integer', ['default' => 14])
            ->create();
    }
}
