<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260209223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user table and bootstrap admin user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id VARCHAR(32) NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, roles JSON NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_app_user_email ON app_user (email)');
        $this->addSql('INSERT INTO app_user (id, email, password_hash, roles) VALUES (\'bootstrapadmin0001\', \'admin@retaia.local\', \'$2y$12$6AGCXyao1Z/Rc2ippIQ8xOhoWwyKr1TaReDVu/jayjEclIawUJjUm\', \'["ROLE_ADMIN"]\')');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_user');
    }
}
