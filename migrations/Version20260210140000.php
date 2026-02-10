<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260210140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add batch move report table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE batch_move_report (batch_id VARCHAR(16) NOT NULL, payload TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(batch_id))');
        $this->addSql('CREATE INDEX idx_batch_move_report_created_at ON batch_move_report (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE batch_move_report');
    }
}
