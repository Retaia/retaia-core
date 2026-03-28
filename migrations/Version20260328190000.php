<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist agent job execution timestamps and actors for ops projections';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processing_job ADD claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE processing_job ADD completed_by VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE processing_job ADD completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE processing_job ADD failed_by VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE processing_job ADD failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_processing_job_claimed_by_status ON processing_job (claimed_by, status)');
        $this->addSql('CREATE INDEX idx_processing_job_completed_by_completed_at ON processing_job (completed_by, completed_at)');
        $this->addSql('CREATE INDEX idx_processing_job_failed_by_failed_at ON processing_job (failed_by, failed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_processing_job_failed_by_failed_at');
        $this->addSql('DROP INDEX idx_processing_job_completed_by_completed_at');
        $this->addSql('DROP INDEX idx_processing_job_claimed_by_status');
        $this->addSql('ALTER TABLE processing_job DROP failed_at');
        $this->addSql('ALTER TABLE processing_job DROP failed_by');
        $this->addSql('ALTER TABLE processing_job DROP completed_at');
        $this->addSql('ALTER TABLE processing_job DROP completed_by');
        $this->addSql('ALTER TABLE processing_job DROP claimed_at');
    }
}
