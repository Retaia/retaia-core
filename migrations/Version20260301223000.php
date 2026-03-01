<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260301223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add correlation_id to processing_job for ingest-to-agent tracing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processing_job ADD correlation_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_processing_job_correlation_id ON processing_job (correlation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_processing_job_correlation_id');
        $this->addSql('ALTER TABLE processing_job DROP COLUMN correlation_id');
    }
}

