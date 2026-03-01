<?php

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

trait FunctionalSchemaTrait
{
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
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS processing_job (id VARCHAR(36) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, job_type VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, claimed_by VARCHAR(32) DEFAULT NULL, lock_token VARCHAR(64) DEFAULT NULL, locked_until DATETIME DEFAULT NULL, result_payload CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
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
}

