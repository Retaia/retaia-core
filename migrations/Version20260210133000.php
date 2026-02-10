<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260210133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add derived upload session and derived asset metadata tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE derived_upload_session (upload_id VARCHAR(24) NOT NULL, asset_uuid VARCHAR(36) NOT NULL, kind VARCHAR(64) NOT NULL, content_type VARCHAR(128) NOT NULL, size_bytes INT NOT NULL, sha256 VARCHAR(64) DEFAULT NULL, status VARCHAR(16) NOT NULL, parts_count INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(upload_id))');
        $this->addSql('CREATE INDEX idx_derived_upload_asset ON derived_upload_session (asset_uuid)');

        $this->addSql('CREATE TABLE asset_derived_file (id VARCHAR(16) NOT NULL, asset_uuid VARCHAR(36) NOT NULL, kind VARCHAR(64) NOT NULL, content_type VARCHAR(128) NOT NULL, size_bytes INT NOT NULL, sha256 VARCHAR(64) DEFAULT NULL, storage_path VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_asset_derived_asset ON asset_derived_file (asset_uuid)');
        $this->addSql('CREATE INDEX idx_asset_derived_asset_kind ON asset_derived_file (asset_uuid, kind)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE asset_derived_file');
        $this->addSql('DROP TABLE derived_upload_session');
    }
}
