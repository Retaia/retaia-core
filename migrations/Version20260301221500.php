<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260301221500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add processing job state_version and update uniqueness to asset+type+state_version';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE processing_job ADD state_version VARCHAR(64) DEFAULT '1' NOT NULL");
        $this->addSql('DROP INDEX IF EXISTS uniq_processing_job_asset_type');
        $this->addSql('CREATE UNIQUE INDEX uniq_processing_job_asset_type_version ON processing_job (asset_uuid, job_type, state_version)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_processing_job_asset_type_version');
        $this->addSql('CREATE UNIQUE INDEX uniq_processing_job_asset_type ON processing_job (asset_uuid, job_type)');
        $this->addSql('ALTER TABLE processing_job DROP COLUMN state_version');
    }
}

