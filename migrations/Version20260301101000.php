<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260301101000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure a single processing job per asset/job_type to prevent concurrent duplicate enqueue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_processing_job_asset_type ON processing_job (asset_uuid, job_type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_processing_job_asset_type');
    }
}
