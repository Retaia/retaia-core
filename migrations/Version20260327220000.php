<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260327220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist agent runtime snapshots for ops endpoints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agent_runtime (agent_id VARCHAR(36) NOT NULL, client_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, agent_version VARCHAR(64) NOT NULL, os_name VARCHAR(32) DEFAULT NULL, os_version VARCHAR(64) DEFAULT NULL, arch VARCHAR(32) DEFAULT NULL, effective_capabilities CLOB NOT NULL, capability_warnings CLOB NOT NULL, last_register_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_heartbeat_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, max_parallel_jobs INT NOT NULL, feature_flags_contract_version VARCHAR(32) DEFAULT NULL, effective_feature_flags_contract_version VARCHAR(32) DEFAULT NULL, server_time_skew_seconds INT DEFAULT NULL, PRIMARY KEY(agent_id))');
        $this->addSql('CREATE INDEX idx_agent_runtime_last_seen_at ON agent_runtime (last_seen_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE agent_runtime');
    }
}
