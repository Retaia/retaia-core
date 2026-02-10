<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260210114000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create asset table for state machine and review metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE asset (uuid VARCHAR(36) NOT NULL, media_type VARCHAR(16) NOT NULL, filename VARCHAR(255) NOT NULL, state VARCHAR(32) NOT NULL, tags JSON NOT NULL, notes TEXT DEFAULT NULL, fields JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(uuid))');
        $this->addSql('CREATE UNIQUE INDEX uniq_asset_uuid ON asset (uuid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE asset');
    }
}
