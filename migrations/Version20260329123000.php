<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add created_at and updated_at timestamps to app_user for ORM timestampable support';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW() NOT NULL');
        $this->addSql('ALTER TABLE app_user ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW() NOT NULL');
        $this->addSql('ALTER TABLE app_user ALTER COLUMN created_at DROP DEFAULT');
        $this->addSql('ALTER TABLE app_user ALTER COLUMN updated_at DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP created_at');
        $this->addSql('ALTER TABLE app_user DROP updated_at');
    }
}
