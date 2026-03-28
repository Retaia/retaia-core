<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328161000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist agent signing public keys and anti-replay nonces';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agent_public_key (agent_id VARCHAR(36) NOT NULL, openpgp_fingerprint VARCHAR(40) NOT NULL, openpgp_public_key CLOB NOT NULL, updated_at INT NOT NULL, PRIMARY KEY(agent_id))');
        $this->addSql('CREATE TABLE agent_signature_nonce (nonce_key VARCHAR(64) NOT NULL, agent_id VARCHAR(36) NOT NULL, expires_at INT NOT NULL, consumed_at INT NOT NULL, PRIMARY KEY(nonce_key))');
        $this->addSql('CREATE INDEX idx_agent_signature_nonce_expires_at ON agent_signature_nonce (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE agent_signature_nonce');
        $this->addSql('DROP TABLE agent_public_key');
    }
}
