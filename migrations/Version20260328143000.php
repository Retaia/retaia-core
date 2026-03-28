<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist technical auth client registry, tokens, device flows and MCP challenges';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE auth_client_registry (client_id VARCHAR(64) NOT NULL, client_kind VARCHAR(32) NOT NULL, secret_key VARCHAR(128) DEFAULT NULL, client_label VARCHAR(255) DEFAULT NULL, openpgp_public_key TEXT DEFAULT NULL, openpgp_fingerprint VARCHAR(40) DEFAULT NULL, registered_at VARCHAR(32) DEFAULT NULL, rotated_at VARCHAR(32) DEFAULT NULL, PRIMARY KEY(client_id))');
        $this->addSql('CREATE TABLE auth_client_access_token (client_id VARCHAR(64) NOT NULL, access_token TEXT NOT NULL, client_kind VARCHAR(32) NOT NULL, issued_at BIGINT NOT NULL, PRIMARY KEY(client_id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_auth_client_access_token_token ON auth_client_access_token (access_token)');
        $this->addSql('CREATE TABLE auth_device_flow (device_code VARCHAR(32) NOT NULL, user_code VARCHAR(16) NOT NULL, client_kind VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, interval_seconds INT NOT NULL, last_polled_at BIGINT NOT NULL, approved_client_id VARCHAR(64) DEFAULT NULL, approved_secret_key VARCHAR(128) DEFAULT NULL, PRIMARY KEY(device_code))');
        $this->addSql('CREATE UNIQUE INDEX uniq_auth_device_flow_user_code ON auth_device_flow (user_code)');
        $this->addSql('CREATE INDEX idx_auth_device_flow_expires_at ON auth_device_flow (expires_at)');
        $this->addSql('CREATE TABLE auth_mcp_challenge (challenge_id VARCHAR(32) NOT NULL, client_id VARCHAR(64) NOT NULL, openpgp_fingerprint VARCHAR(40) NOT NULL, challenge VARCHAR(128) NOT NULL, expires_at BIGINT NOT NULL, used BOOLEAN NOT NULL, used_at BIGINT DEFAULT NULL, PRIMARY KEY(challenge_id))');
        $this->addSql('CREATE INDEX idx_auth_mcp_challenge_expires_at ON auth_mcp_challenge (expires_at)');

        $this->addSql("INSERT INTO auth_client_registry (client_id, client_kind, secret_key, client_label, openpgp_public_key, openpgp_fingerprint, registered_at, rotated_at) VALUES ('agent-default', 'AGENT', 'agent-secret', NULL, NULL, NULL, NULL, NULL)");
        $this->addSql("INSERT INTO auth_client_registry (client_id, client_kind, secret_key, client_label, openpgp_public_key, openpgp_fingerprint, registered_at, rotated_at) VALUES ('mcp-default', 'MCP', 'mcp-secret', NULL, NULL, NULL, NULL, NULL)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE auth_mcp_challenge');
        $this->addSql('DROP TABLE auth_device_flow');
        $this->addSql('DROP TABLE auth_client_access_token');
        $this->addSql('DROP TABLE auth_client_registry');
    }
}
