<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make ingest scan state multi-storage aware';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingest_scan_file ADD storage_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('UPDATE ingest_scan_file SET storage_id = \'nas-main\' WHERE storage_id IS NULL');
        $this->addSql('ALTER TABLE ingest_scan_file ALTER COLUMN storage_id SET NOT NULL');
        $this->addSql('ALTER TABLE ingest_scan_file DROP CONSTRAINT ingest_scan_file_pkey');
        $this->addSql('ALTER TABLE ingest_scan_file ADD PRIMARY KEY (storage_id, path)');
        $this->addSql('CREATE INDEX idx_ingest_scan_file_storage_status ON ingest_scan_file (storage_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ingest_scan_file_storage_status');
        $this->addSql('ALTER TABLE ingest_scan_file DROP CONSTRAINT ingest_scan_file_pkey');
        $this->addSql('ALTER TABLE ingest_scan_file ADD PRIMARY KEY (path)');
        $this->addSql('ALTER TABLE ingest_scan_file DROP COLUMN storage_id');
    }
}
