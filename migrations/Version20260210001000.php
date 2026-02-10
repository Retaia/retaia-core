<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260210001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email verification flag on users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD email_verified BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('UPDATE app_user SET email_verified = TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP email_verified');
    }
}
