<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260210174000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create asset operation lock table for move/purge concurrency safety';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE asset_operation_lock (id VARCHAR(32) NOT NULL, asset_uuid VARCHAR(36) NOT NULL, lock_type VARCHAR(32) NOT NULL, actor_id VARCHAR(64) NOT NULL, acquired_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, released_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_asset_operation_lock_asset ON asset_operation_lock (asset_uuid)');
        $this->addSql('CREATE INDEX idx_asset_operation_lock_active ON asset_operation_lock (released_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_asset_operation_lock_active_type ON asset_operation_lock (asset_uuid, lock_type) WHERE released_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE asset_operation_lock');
    }
}

