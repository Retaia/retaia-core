<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist interactive user auth sessions and refresh tokens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_auth_session (session_id VARCHAR(32) NOT NULL, access_token TEXT NOT NULL, refresh_token VARCHAR(255) NOT NULL, access_expires_at BIGINT NOT NULL, refresh_expires_at BIGINT NOT NULL, user_id VARCHAR(32) NOT NULL, email VARCHAR(180) NOT NULL, client_id VARCHAR(64) NOT NULL, client_kind VARCHAR(32) NOT NULL, created_at BIGINT NOT NULL, last_used_at BIGINT NOT NULL, PRIMARY KEY(session_id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_auth_session_refresh_token ON user_auth_session (refresh_token)');
        $this->addSql('CREATE INDEX idx_user_auth_session_user_id ON user_auth_session (user_id)');
        $this->addSql('CREATE INDEX idx_user_auth_session_refresh_expires_at ON user_auth_session (refresh_expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_auth_session');
    }
}
