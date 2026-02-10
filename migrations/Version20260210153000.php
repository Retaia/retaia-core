<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260210153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ingest_scan_file table for polling scan state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ingest_scan_file (path VARCHAR(1024) NOT NULL, size_bytes BIGINT NOT NULL, mtime TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, stable_count INT NOT NULL, status VARCHAR(32) NOT NULL, first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(path))');
        $this->addSql('CREATE INDEX idx_ingest_scan_file_status ON ingest_scan_file (status)');
        $this->addSql('CREATE INDEX idx_ingest_scan_file_last_seen ON ingest_scan_file (last_seen_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ingest_scan_file');
    }
}

