<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260327101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fencing token to processing jobs and rename generate_proxy to generate_preview';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processing_job ADD fencing_token INT DEFAULT NULL');
        $this->addSql("UPDATE processing_job SET job_type = 'generate_preview' WHERE job_type = 'generate_proxy'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE processing_job SET job_type = 'generate_proxy' WHERE job_type = 'generate_preview'");
        $this->addSql('ALTER TABLE processing_job DROP COLUMN fencing_token');
    }
}
