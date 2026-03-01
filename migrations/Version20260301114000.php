<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260301114000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store unmatched ingest sidecars for diagnostics endpoint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ingest_unmatched_sidecar (path VARCHAR(1024) NOT NULL, reason VARCHAR(64) NOT NULL, detected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(path))');
        $this->addSql('CREATE INDEX idx_ingest_unmatched_detected_at ON ingest_unmatched_sidecar (detected_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ingest_unmatched_sidecar');
    }
}

