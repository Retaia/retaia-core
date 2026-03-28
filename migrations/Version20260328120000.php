<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist user two-factor state and recovery codes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_two_factor_state (user_id VARCHAR(32) NOT NULL, enabled BOOLEAN NOT NULL, pending_secret_encrypted TEXT DEFAULT NULL, secret_encrypted TEXT DEFAULT NULL, recovery_code_hashes TEXT NOT NULL, legacy_recovery_code_sha256 TEXT NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, PRIMARY KEY(user_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_two_factor_state');
    }
}
