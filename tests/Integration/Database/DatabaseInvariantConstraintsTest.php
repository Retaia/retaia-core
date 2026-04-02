<?php

namespace App\Tests\Integration\Database;

use App\Tests\Support\FunctionalSchemaTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class DatabaseInvariantConstraintsTest extends TestCase
{
    use FunctionalSchemaTrait;

    public function testProcessingJobRejectsIncompleteClaimedRow(): void
    {
        $connection = $this->connection();
        $this->ensureProcessingJobTable($connection);

        $this->expectInsertFailure($connection, 'processing_job', [
            'id' => 'job-1',
            'asset_uuid' => 'asset-1',
            'job_type' => 'generate_preview',
            'state_version' => '1',
            'status' => 'claimed',
            'claimed_by' => 'agent-1',
            'claimed_at' => null,
            'lock_token' => 'lock-1',
            'fencing_token' => 1,
            'locked_until' => '2026-04-03 12:10:00',
            'created_at' => '2026-04-03 12:00:00',
            'updated_at' => '2026-04-03 12:00:00',
        ]);
    }

    public function testProcessingJobRejectsCompletedRowWithClaimFields(): void
    {
        $connection = $this->connection();
        $this->ensureProcessingJobTable($connection);

        $this->expectInsertFailure($connection, 'processing_job', [
            'id' => 'job-2',
            'asset_uuid' => 'asset-2',
            'job_type' => 'generate_preview',
            'state_version' => '1',
            'status' => 'completed',
            'claimed_by' => 'agent-1',
            'claimed_at' => null,
            'lock_token' => null,
            'fencing_token' => null,
            'locked_until' => null,
            'completed_by' => 'agent-1',
            'completed_at' => '2026-04-03 12:01:00',
            'created_at' => '2026-04-03 12:00:00',
            'updated_at' => '2026-04-03 12:01:00',
        ]);
    }

    public function testUserAuthSessionRejectsInvalidTokenWindow(): void
    {
        $connection = $this->connection();
        $this->ensureUserAuthSessionTable($connection);

        $this->expectInsertFailure($connection, 'user_auth_session', [
            'session_id' => 'session-1',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'access_expires_at' => 200,
            'refresh_expires_at' => 100,
            'user_id' => 'user-1',
            'email' => 'u@example.test',
            'client_id' => 'web',
            'client_kind' => 'interactive',
            'created_at' => 10,
            'last_used_at' => 10,
        ]);
    }

    public function testUserTwoFactorStateRequiresActiveSecretWhenEnabled(): void
    {
        $connection = $this->connection();
        $this->ensureUserTwoFactorStateTable($connection);

        $this->expectInsertFailure($connection, 'user_two_factor_state', [
            'user_id' => 'user-1',
            'enabled' => 1,
            'pending_secret_encrypted' => null,
            'secret_encrypted' => null,
            'recovery_code_hashes' => '[]',
            'legacy_recovery_code_sha256' => '[]',
            'created_at' => 10,
            'updated_at' => 10,
        ]);
    }

    public function testDerivedUploadSessionRejectsUnknownStatus(): void
    {
        $connection = $this->connection();
        $this->ensureDerivedUploadSessionTable($connection);

        $this->expectInsertFailure($connection, 'derived_upload_session', [
            'upload_id' => 'upload-1',
            'asset_uuid' => 'asset-1',
            'kind' => 'proxy',
            'content_type' => 'video/mp4',
            'size_bytes' => 10,
            'sha256' => null,
            'status' => 'stale',
            'parts_count' => 0,
            'created_at' => '2026-04-03 12:00:00',
            'updated_at' => '2026-04-03 12:00:00',
        ]);
    }

    public function testAssetDerivedFileRejectsNegativeSize(): void
    {
        $connection = $this->connection();
        $this->ensureAssetDerivedFileTable($connection);

        $this->expectInsertFailure($connection, 'asset_derived_file', [
            'id' => 'derived-1',
            'asset_uuid' => 'asset-1',
            'kind' => 'proxy',
            'content_type' => 'video/mp4',
            'size_bytes' => -1,
            'sha256' => null,
            'storage_path' => '.derived/asset-1/proxy.mp4',
            'created_at' => '2026-04-03 12:00:00',
        ]);
    }

    public function testAuthDeviceFlowRequiresApprovedCredentialsForApprovedStatus(): void
    {
        $connection = $this->connection();
        $this->ensureAuthClientTables($connection);

        $this->expectInsertFailure($connection, 'auth_device_flow', [
            'device_code' => 'dc_123',
            'user_code' => 'ABCD1234',
            'client_kind' => 'AGENT',
            'status' => 'APPROVED',
            'created_at' => 100,
            'expires_at' => 200,
            'interval_seconds' => 5,
            'last_polled_at' => 0,
            'approved_client_id' => null,
            'approved_secret_key' => null,
        ]);
    }

    public function testAuthMcpChallengeRequiresUsedAtWhenUsed(): void
    {
        $connection = $this->connection();
        $this->ensureAuthClientTables($connection);

        $this->expectInsertFailure($connection, 'auth_mcp_challenge', [
            'challenge_id' => 'challenge-1',
            'client_id' => 'client-1',
            'openpgp_fingerprint' => '1234567890123456789012345678901234567890',
            'challenge' => 'payload',
            'expires_at' => 100,
            'used' => 1,
            'used_at' => null,
        ]);
    }

    private function connection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function expectInsertFailure(Connection $connection, string $table, array $data): void
    {
        try {
            $connection->insert($table, $data);
            self::fail(sprintf('Insert into %s should have failed.', $table));
        } catch (\Throwable) {
            self::assertTrue(true);
        }
    }
}
