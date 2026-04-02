<?php

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

trait ProcessingJobSchemaTrait
{
    /**
     * @param array{id_length?: int, state_version_length?: int, state_version_default?: string, actor_length?: int, unique_index?: string|null, unique_columns?: string|null} $options
     */
    private function createProcessingJobTable(Connection $connection, array $options = []): void
    {
        $idLength = $options['id_length'] ?? 32;
        $stateVersionLength = $options['state_version_length'] ?? 16;
        $stateVersionDefault = $options['state_version_default'] ?? null;
        $actorLength = $options['actor_length'] ?? 64;

        $stateVersionDefaultSql = $stateVersionDefault === null
            ? ''
            : sprintf(" DEFAULT '%s'", str_replace("'", "''", $stateVersionDefault));

        $connection->executeStatement(sprintf(
            'CREATE TABLE processing_job (id VARCHAR(%1$d) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, job_type VARCHAR(64) NOT NULL, state_version VARCHAR(%2$d) NOT NULL%3$s, status VARCHAR(16) NOT NULL, correlation_id VARCHAR(64) DEFAULT NULL, claimed_by VARCHAR(%4$d) DEFAULT NULL, claimed_at DATETIME DEFAULT NULL, lock_token VARCHAR(64) DEFAULT NULL, fencing_token INTEGER DEFAULT NULL, locked_until DATETIME DEFAULT NULL, completed_by VARCHAR(%4$d) DEFAULT NULL, completed_at DATETIME DEFAULT NULL, failed_by VARCHAR(%4$d) DEFAULT NULL, failed_at DATETIME DEFAULT NULL, result_payload CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)',
            $idLength,
            $stateVersionLength,
            $stateVersionDefaultSql,
            $actorLength,
        ));

        $uniqueIndex = $options['unique_index'] ?? null;
        $uniqueColumns = $options['unique_columns'] ?? null;
        if (is_string($uniqueIndex) && $uniqueIndex !== '' && is_string($uniqueColumns) && $uniqueColumns !== '') {
            $connection->executeStatement(sprintf('CREATE UNIQUE INDEX %s ON processing_job (%s)', $uniqueIndex, $uniqueColumns));
        }
    }
}
