<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260210162000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ingest_path_audit table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ingest_path_audit (id VARCHAR(32) NOT NULL, asset_uuid VARCHAR(36) NOT NULL, from_path VARCHAR(1024) NOT NULL, to_path VARCHAR(1024) NOT NULL, reason VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_ingest_path_audit_asset ON ingest_path_audit (asset_uuid)');
        $this->addSql('CREATE INDEX idx_ingest_path_audit_created_at ON ingest_path_audit (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ingest_path_audit');
    }
}

