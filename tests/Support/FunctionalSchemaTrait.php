<?php

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

trait FunctionalSchemaTrait
{
    protected function ensureAuthClientTables(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS auth_client_registry (client_id VARCHAR(64) PRIMARY KEY NOT NULL, client_kind VARCHAR(32) NOT NULL, secret_key VARCHAR(128) DEFAULT NULL, client_label VARCHAR(255) DEFAULT NULL, openpgp_public_key CLOB DEFAULT NULL, openpgp_fingerprint VARCHAR(40) DEFAULT NULL, registered_at VARCHAR(32) DEFAULT NULL, rotated_at VARCHAR(32) DEFAULT NULL)');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS auth_client_access_token (client_id VARCHAR(64) PRIMARY KEY NOT NULL, access_token CLOB NOT NULL, client_kind VARCHAR(32) NOT NULL, issued_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_auth_client_access_token_token ON auth_client_access_token (access_token)');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS auth_device_flow (device_code VARCHAR(32) PRIMARY KEY NOT NULL, user_code VARCHAR(16) NOT NULL, client_kind VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, interval_seconds INTEGER NOT NULL, last_polled_at INTEGER NOT NULL, approved_client_id VARCHAR(64) DEFAULT NULL, approved_secret_key VARCHAR(128) DEFAULT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_auth_device_flow_user_code ON auth_device_flow (user_code)');
        $connection->executeStatement('CREATE INDEX IF NOT EXISTS idx_auth_device_flow_expires_at ON auth_device_flow (expires_at)');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS auth_mcp_challenge (challenge_id VARCHAR(32) PRIMARY KEY NOT NULL, client_id VARCHAR(64) NOT NULL, openpgp_fingerprint VARCHAR(40) NOT NULL, challenge VARCHAR(128) NOT NULL, expires_at INTEGER NOT NULL, used BOOLEAN NOT NULL, used_at INTEGER DEFAULT NULL)');
        $connection->executeStatement('CREATE INDEX IF NOT EXISTS idx_auth_mcp_challenge_expires_at ON auth_mcp_challenge (expires_at)');
        $connection->executeStatement("INSERT OR IGNORE INTO auth_client_registry (client_id, client_kind, secret_key, client_label, openpgp_public_key, openpgp_fingerprint, registered_at, rotated_at) VALUES ('agent-default', 'AGENT', 'agent-secret', NULL, NULL, NULL, NULL, NULL)");
        $connection->executeStatement("INSERT OR IGNORE INTO auth_client_registry (client_id, client_kind, secret_key, client_label, openpgp_public_key, openpgp_fingerprint, registered_at, rotated_at) VALUES ('mcp-default', 'MCP', 'mcp-secret', NULL, NULL, NULL, NULL, NULL)");
    }

    protected function ensureUserTwoFactorStateTable(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS user_two_factor_state (user_id VARCHAR(32) PRIMARY KEY NOT NULL, enabled BOOLEAN NOT NULL, pending_secret_encrypted CLOB DEFAULT NULL, secret_encrypted CLOB DEFAULT NULL, recovery_code_hashes CLOB NOT NULL, legacy_recovery_code_sha256 CLOB NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');
    }

    protected function ensureUserAuthSessionTable(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS user_auth_session (session_id VARCHAR(32) PRIMARY KEY NOT NULL, access_token CLOB NOT NULL, refresh_token VARCHAR(255) NOT NULL, access_expires_at INTEGER NOT NULL, refresh_expires_at INTEGER NOT NULL, user_id VARCHAR(32) NOT NULL, email VARCHAR(180) NOT NULL, client_id VARCHAR(64) NOT NULL, client_kind VARCHAR(32) NOT NULL, created_at INTEGER NOT NULL, last_used_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_user_auth_session_refresh_token ON user_auth_session (refresh_token)');
        $connection->executeStatement('CREATE INDEX IF NOT EXISTS idx_user_auth_session_user_id ON user_auth_session (user_id)');
        $connection->executeStatement('CREATE INDEX IF NOT EXISTS idx_user_auth_session_refresh_expires_at ON user_auth_session (refresh_expires_at)');
    }

    protected function ensureOperationLockTable(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS asset_operation_lock (id VARCHAR(32) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, lock_type VARCHAR(32) NOT NULL, actor_id VARCHAR(64) NOT NULL, acquired_at DATETIME NOT NULL, released_at DATETIME DEFAULT NULL)');
    }

    protected function ensureIdempotencyTable(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS idempotency_entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, actor_id VARCHAR(64) NOT NULL, method VARCHAR(8) NOT NULL, path VARCHAR(255) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, request_hash VARCHAR(64) NOT NULL, response_status INTEGER NOT NULL, response_body CLOB NOT NULL, created_at DATETIME NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_idempotency_key_scope ON idempotency_entry (actor_id, method, path, idempotency_key)');
    }

    protected function ensureProcessingJobTable(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS processing_job (id VARCHAR(36) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, job_type VARCHAR(64) NOT NULL, state_version VARCHAR(64) NOT NULL DEFAULT \'1\', status VARCHAR(16) NOT NULL, correlation_id VARCHAR(64) DEFAULT NULL, claimed_by VARCHAR(32) DEFAULT NULL, lock_token VARCHAR(64) DEFAULT NULL, fencing_token INTEGER DEFAULT NULL, locked_until DATETIME DEFAULT NULL, result_payload CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_processing_job_asset_type_version ON processing_job (asset_uuid, job_type, state_version)');
    }

    protected function ensureIngestScanTable(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS ingest_scan_file (path VARCHAR(1024) PRIMARY KEY NOT NULL, size_bytes INTEGER NOT NULL, mtime DATETIME NOT NULL, stable_count INTEGER NOT NULL, status VARCHAR(32) NOT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL)');
    }

    protected function ensureUnmatchedSidecarTable(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS ingest_unmatched_sidecar (path VARCHAR(1024) PRIMARY KEY NOT NULL, reason VARCHAR(64) NOT NULL, detected_at DATETIME NOT NULL)');
    }

    protected function ensureAssetDerivedFileTable(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS asset_derived_file (id VARCHAR(16) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, kind VARCHAR(64) NOT NULL, content_type VARCHAR(128) NOT NULL, size_bytes INTEGER NOT NULL, sha256 VARCHAR(64) DEFAULT NULL, storage_path VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL)');
    }

    protected function ensureAgentRuntimeTable(Connection $connection): void
    {
        $connection->executeStatement("CREATE TABLE IF NOT EXISTS agent_runtime (agent_id VARCHAR(36) PRIMARY KEY NOT NULL, client_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, agent_version VARCHAR(64) NOT NULL, os_name VARCHAR(32) DEFAULT NULL, os_version VARCHAR(64) DEFAULT NULL, arch VARCHAR(32) DEFAULT NULL, effective_capabilities CLOB NOT NULL, capability_warnings CLOB NOT NULL, last_register_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, last_heartbeat_at DATETIME DEFAULT NULL, max_parallel_jobs INTEGER NOT NULL, feature_flags_contract_version VARCHAR(32) DEFAULT NULL, effective_feature_flags_contract_version VARCHAR(32) DEFAULT NULL, server_time_skew_seconds INTEGER DEFAULT NULL)");
        $connection->executeStatement('CREATE INDEX IF NOT EXISTS idx_agent_runtime_last_seen_at ON agent_runtime (last_seen_at)');
    }

    protected function ensureAgentSignatureTables(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS agent_public_key (agent_id VARCHAR(36) PRIMARY KEY NOT NULL, openpgp_fingerprint VARCHAR(40) NOT NULL, openpgp_public_key CLOB NOT NULL, updated_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS agent_signature_nonce (nonce_key VARCHAR(64) PRIMARY KEY NOT NULL, agent_id VARCHAR(36) NOT NULL, expires_at INTEGER NOT NULL, consumed_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE INDEX IF NOT EXISTS idx_agent_signature_nonce_expires_at ON agent_signature_nonce (expires_at)');
    }
}
