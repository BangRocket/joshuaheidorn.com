<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateTactaTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('tacta_games')
            ->addColumn('code', 'string', ['limit' => 12, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 16, 'null' => false, 'default' => 'lobby'])
            ->addColumn('current_seat', 'integer', ['null' => true])
            ->addColumn('turn_order', 'text', ['null' => true])
            ->addColumn('seq', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['code'], ['unique' => true])
            ->addIndex(['updated_at'])
            ->create();

        $this->table('tacta_players')
            ->addColumn('game_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('seat', 'integer', ['null' => false])
            ->addColumn('color', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('display_name', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('guest_token', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('deck', 'text', ['null' => true])
            ->addColumn('is_host', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('joined_at', 'datetime', ['null' => false])
            ->addIndex(['game_id', 'seat'], ['unique' => true])
            ->addIndex(['game_id', 'color'], ['unique' => true])
            ->addIndex(['guest_token'])
            ->addForeignKey('game_id', 'tacta_games', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('tacta_moves')
            ->addColumn('game_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('seq', 'integer', ['null' => false])
            ->addColumn('seat', 'integer', ['null' => false])
            ->addColumn('card_id', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('draw_end', 'string', ['limit' => 8, 'null' => false])
            ->addColumn('x', 'integer', ['null' => false])
            ->addColumn('y', 'integer', ['null' => false])
            ->addColumn('rotation', 'integer', ['null' => false])
            ->addColumn('mirror', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('z', 'integer', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['game_id', 'seq'])
            ->addIndex(['game_id', 'z'], ['unique' => true]) // safety net: one card per board ordinal
            ->addForeignKey('game_id', 'tacta_games', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
