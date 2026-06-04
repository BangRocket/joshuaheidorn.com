<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateInitialSchema extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('email', 'string', ['limit' => 190])
            ->addColumn('password_hash', 'string', ['limit' => 255])
            ->addColumn('name', 'string', ['limit' => 190])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['email'], ['unique' => true])
            ->create();

        $this->table('media')
            ->addColumn('filename', 'string', ['limit' => 255])
            ->addColumn('path', 'string', ['limit' => 512])
            ->addColumn('alt', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('width', 'integer', ['null' => true])
            ->addColumn('height', 'integer', ['null' => true])
            ->addColumn('mime', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->create();

        $this->table('terms')
            ->addColumn('taxonomy', 'string', ['limit' => 32])
            ->addColumn('slug', 'string', ['limit' => 191])
            ->addColumn('label', 'string', ['limit' => 191])
            ->addIndex(['taxonomy', 'slug'], ['unique' => true])
            ->create();

        $this->table('term_relationships')
            ->addColumn('term_id', 'integer', ['signed' => false])
            ->addColumn('content_type', 'string', ['limit' => 32])
            ->addColumn('content_id', 'integer', ['signed' => false])
            ->addIndex(['content_type', 'content_id'])
            ->addIndex(['term_id'])
            ->addForeignKey('term_id', 'terms', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('posts')
            ->addColumn('slug', 'string', ['limit' => 191])
            ->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('excerpt', 'text', ['null' => true])
            ->addColumn('body_md', 'text', ['null' => true, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG])
            ->addColumn('featured_image_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'draft'])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['slug'], ['unique' => true])
            ->addForeignKey('featured_image_id', 'media', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('projects')
            ->addColumn('slug', 'string', ['limit' => 191])
            ->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('summary', 'text', ['null' => true])
            ->addColumn('body_md', 'text', ['null' => true, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG])
            ->addColumn('source_url', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('external_url', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('featured', 'boolean', ['default' => false])
            ->addColumn('featured_image_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'draft'])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['slug'], ['unique' => true])
            ->addForeignKey('featured_image_id', 'media', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('pages')
            ->addColumn('slug', 'string', ['limit' => 191])
            ->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('body_md', 'text', ['null' => true, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'draft'])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['slug'], ['unique' => true])
            ->create();

        $this->table('resume_meta')
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('headline', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('headlines', 'text', ['null' => true])
            ->addColumn('summary', 'text', ['null' => true])
            ->addColumn('contact', 'text', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->create();

        $this->table('experience')
            ->addColumn('company', 'string', ['limit' => 255])
            ->addColumn('role', 'string', ['limit' => 255])
            ->addColumn('start', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('end', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('location', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('bullets', 'text', ['null' => true])
            ->addColumn('tags', 'text', ['null' => true])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->create();

        $this->table('education')
            ->addColumn('school', 'string', ['limit' => 255])
            ->addColumn('degree', 'string', ['limit' => 255])
            ->addColumn('start', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('end', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('location', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->create();

        $this->table('skill_categories')
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->create();

        $this->table('skills')
            ->addColumn('category_id', 'integer', ['signed' => false])
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('proficiency', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->addForeignKey('category_id', 'skill_categories', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('settings')
            ->addColumn('setting_key', 'string', ['limit' => 100])
            ->addColumn('setting_value', 'text', ['null' => true])
            ->addIndex(['setting_key'], ['unique' => true])
            ->create();

        $this->table('menu_items')
            ->addColumn('label', 'string', ['limit' => 191])
            ->addColumn('url', 'string', ['limit' => 512])
            ->addColumn('target', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->create();
    }
}
