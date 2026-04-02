<?php

namespace App\Tests\Functional;

use App\Tests\Support\AgentSigningTestHelper;
use App\Tests\Support\ApiAuthClientTrait;
use App\Tests\Support\FunctionalSchemaTrait;
use Doctrine\DBAL\Connection;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class JobApiTest extends WebTestCase
{
    use RecreateDatabaseTrait;
    use ApiAuthClientTrait;
    use FunctionalSchemaTrait;

    public function testClaimRejectsForgedAgentSignature(): void
    {
        $client = $this->bootClient();
        $this->seedJob('job-signature-forged');
        $this->loginAgent($client);

        $headers = AgentSigningTestHelper::signedHeaders('POST', '/api/v1/jobs/job-signature-forged/claim', []);
        $headers['HTTP_X_RETAIA_SIGNATURE'] = base64_encode('forged-signature');

        $client->jsonRequest('POST', '/api/v1/jobs/job-signature-forged/claim', [], $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['X-Retaia-Signature'], $payload['details']['invalid_headers'] ?? null);
    }

    public function testClaimRejectsReplayNonce(): void
    {
        $client = $this->bootClient();
        $this->seedJob('job-signature-replay');
        $this->loginAgent($client);

        $headers = AgentSigningTestHelper::signedHeaders('POST', '/api/v1/jobs/job-signature-replay/claim', [], 'job-replay-nonce');

        $client->jsonRequest('POST', '/api/v1/jobs/job-signature-replay/claim', [], $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/jobs/job-signature-replay/claim', [], $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['X-Retaia-Signature-Nonce'], $payload['details']['invalid_headers'] ?? null);
    }

    public function testLeaseExpiryMakesJobClaimableAgain(): void
    {
        $first = $this->bootClient();
        $this->seedJob('job-2');
        $this->loginAgent($first);

        $this->signedJsonRequestAsAgent($first, 'POST', '/api/v1/jobs/job-2/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE processing_job SET locked_until = :expiredAt WHERE id = :id',
            [
                'expiredAt' => (new \DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s'),
                'id' => 'job-2',
            ]
        );

        $this->signedJsonRequestAsAgent($first, 'POST', '/api/v1/jobs/job-2/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testHeartbeatExtendsLock(): void
    {
        $client = $this->bootClient();
        $this->seedJob('job-3');
        $this->loginAgent($client);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-3/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $claimPayload = json_decode((string) $client->getResponse()->getContent(), true);
        $fencingToken = (int) ($claimPayload['fencing_token'] ?? 0);
        self::assertGreaterThanOrEqual(1, $fencingToken);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-3/heartbeat', [
            'lock_token' => $claimPayload['lock_token'] ?? null,
            'fencing_token' => $fencingToken,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $heartbeatPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(
            strtotime((string) ($claimPayload['locked_until'] ?? '1970-01-01T00:00:00Z')),
            strtotime((string) ($heartbeatPayload['locked_until'] ?? '1970-01-01T00:00:00Z')),
        );
        self::assertSame($fencingToken + 1, $heartbeatPayload['fencing_token'] ?? null);
    }

    public function testHeartbeatReturnsStaleLockTokenWhenClaimedByAnotherToken(): void
    {
        $client = $this->bootClient();
        $this->seedJob('job-heartbeat-stale');
        $this->loginAgent($client);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-heartbeat-stale/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $claimPayload = json_decode((string) $client->getResponse()->getContent(), true);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-heartbeat-stale/heartbeat', [
            'lock_token' => 'wrong-lock-token',
            'fencing_token' => $claimPayload['fencing_token'] ?? null,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('STALE_LOCK_TOKEN', $payload['code'] ?? null);
    }

    public function testSubmitIsIdempotentWithSamePayloadAndConflictsOnDifferentPayload(): void
    {
        $client = $this->bootClient();
        $this->seedJob('job-4');
        $this->loginAgent($client);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-4/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $claimPayload = json_decode((string) $client->getResponse()->getContent(), true);
        $lockToken = $claimPayload['lock_token'] ?? null;
        $fencingToken = (int) ($claimPayload['fencing_token'] ?? 0);
        self::assertIsString($lockToken);
        self::assertGreaterThanOrEqual(1, $fencingToken);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-4/submit', [
            'lock_token' => $lockToken,
            'fencing_token' => $fencingToken,
            'job_type' => 'extract_facts',
            'result' => ['facts_patch' => ['duration_ms' => 4200]],
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'idempo-job-4',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $firstPayload = json_decode((string) $client->getResponse()->getContent(), true);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-4/submit', [
            'lock_token' => $lockToken,
            'fencing_token' => $fencingToken,
            'job_type' => 'extract_facts',
            'result' => ['facts_patch' => ['duration_ms' => 4200]],
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'idempo-job-4',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $secondPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($firstPayload, $secondPayload);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-4/submit', [
            'lock_token' => $lockToken,
            'fencing_token' => $fencingToken,
            'job_type' => 'extract_facts',
            'result' => ['facts_patch' => ['duration_ms' => 4300]],
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'idempo-job-4',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $conflictPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('IDEMPOTENCY_CONFLICT', $conflictPayload['code'] ?? null);
    }

    public function testListReturnsClaimableJobsForAgent(): void
    {
        $client = $this->bootClient();
        $this->seedJob('job-list-1');
        $this->seedJob('job-list-2');
        $this->loginAgent($client);

        $client->request('GET', '/api/v1/jobs?limit=1');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertCount(1, $payload['items'] ?? []);
        $job = $payload['items'][0] ?? null;
        self::assertIsArray($job);
        self::assertSame('job-list-1', $job['job_id'] ?? null);
        self::assertArrayHasKey('source', $job);
        self::assertSame('nas-main', $job['source']['storage_id'] ?? null);
        self::assertNotSame('', (string) ($job['source']['original_relative'] ?? ''));
        self::assertFalse(str_starts_with((string) ($job['source']['original_relative'] ?? ''), '/'));
        self::assertArrayHasKey('required_capabilities', $job);
        self::assertIsArray($job['required_capabilities'] ?? null);
    }

    public function testClaimReturnsConflictWhenAssetHasActiveOperationLock(): void
    {
        $client = $this->bootClient();
        $jobId = 'job-locked';
        $this->seedJob($jobId);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('asset_operation_lock', [
            'id' => bin2hex(random_bytes(16)),
            'asset_uuid' => 'asset-'.$jobId,
            'lock_type' => 'asset_move_lock',
            'actor_id' => 'test',
            'acquired_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'released_at' => null,
        ]);

        $this->loginAgent($client);
        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/'.$jobId.'/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('STATE_CONFLICT', $payload['code'] ?? null);
    }

    public function testFailHandlesValidationConflictAndSuccess(): void
    {
        $client = $this->bootClient();
        $this->seedJob('job-fail-1');
        $this->seedJob('job-fail-2');
        $this->loginAgent($client);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-fail-1/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $claimPayload = json_decode((string) $client->getResponse()->getContent(), true);
        $lockToken = (string) ($claimPayload['lock_token'] ?? '');
        $fencingToken = (int) ($claimPayload['fencing_token'] ?? 0);
        self::assertNotSame('', $lockToken);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-fail-1/fail', [
            'lock_token' => $lockToken,
            'error_code' => 'ERR_GENERIC',
            'message' => 'failed',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $missingKey = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('MISSING_IDEMPOTENCY_KEY', $missingKey['code'] ?? null);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-fail-1/fail', [
            'lock_token' => $lockToken,
            'fencing_token' => $fencingToken,
            'error_code' => 'ERR_GENERIC',
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'job-fail-validation',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $validationPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $validationPayload['code'] ?? null);
        self::assertStringContainsString('error_code and message are required', (string) ($validationPayload['message'] ?? ''));

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-fail-2/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $claimPayload2 = json_decode((string) $client->getResponse()->getContent(), true);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-fail-2/fail', [
            'lock_token' => 'wrong-lock',
            'fencing_token' => $claimPayload2['fencing_token'] ?? null,
            'error_code' => 'ERR_GENERIC',
            'message' => 'failed',
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'job-fail-conflict',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $conflictPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('STALE_LOCK_TOKEN', $conflictPayload['code'] ?? null);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-fail-1/fail', [
            'lock_token' => $lockToken,
            'fencing_token' => $fencingToken,
            'error_code' => 'ERR_RETRYABLE',
            'message' => 'temporary failure',
            'retryable' => true,
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'job-fail-success',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $successPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('pending', $successPayload['status'] ?? null);
    }

    public function testSubmitExtractFactsMovesAssetToProcessingReviewAndStoresFactsPatch(): void
    {
        $client = $this->bootClient();
        $this->seedJob('job-submit-progression');
        $this->loginAgent($client);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-submit-progression/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $claimPayload = json_decode((string) $client->getResponse()->getContent(), true);
        $lockToken = (string) ($claimPayload['lock_token'] ?? '');
        $fencingToken = (int) ($claimPayload['fencing_token'] ?? 0);
        self::assertNotSame('', $lockToken);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-submit-progression/submit', [
            'lock_token' => $lockToken,
            'fencing_token' => $fencingToken,
            'job_type' => 'extract_facts',
            'result' => ['facts_patch' => ['duration_ms' => 1234]],
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'submit-progression-extract-facts',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $assetState = (string) $connection->fetchOne('SELECT state FROM asset WHERE uuid = :uuid', ['uuid' => 'asset-job-submit-progression']);
        self::assertSame('PROCESSING_REVIEW', $assetState);
        $fieldsRaw = (string) $connection->fetchOne('SELECT fields FROM asset WHERE uuid = :uuid', ['uuid' => 'asset-job-submit-progression']);
        $fields = json_decode($fieldsRaw, true);
        self::assertIsArray($fields);
        self::assertSame(1234, $fields['facts']['duration_ms'] ?? null);
        self::assertTrue((bool) ($fields['facts_done'] ?? false));
    }

    public function testRetryableFailClearsClaimOwnershipAndFencingToken(): void
    {
        $client = $this->bootClient();
        $this->seedJob('job-fail-reset');
        $this->loginAgent($client);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-fail-reset/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $claimPayload = json_decode((string) $client->getResponse()->getContent(), true);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-fail-reset/fail', [
            'lock_token' => $claimPayload['lock_token'] ?? null,
            'fencing_token' => $claimPayload['fencing_token'] ?? null,
            'error_code' => 'ERR_RETRYABLE',
            'message' => 'temporary failure',
            'retryable' => true,
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'job-fail-reset',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $row = $connection->fetchAssociative(
            'SELECT status, claimed_by, lock_token, fencing_token FROM processing_job WHERE id = :id',
            ['id' => 'job-fail-reset']
        );
        self::assertIsArray($row);
        self::assertSame('pending', $row['status'] ?? null);
        self::assertNull($row['claimed_by'] ?? null);
        self::assertNull($row['lock_token'] ?? null);
        self::assertNull($row['fencing_token'] ?? null);
    }

    public function testLeaseMutationsAreRejectedForAnotherAuthenticatedPrincipal(): void
    {
        $client = $this->bootClient();
        $this->seedJob('job-submit-binding');
        $this->loginAgent($client);

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-submit-binding/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $claimPayload = json_decode((string) $client->getResponse()->getContent(), true);

        $this->loginAgentAs($client, 'agent-2@retaia.local');

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/jobs/job-submit-binding/submit', [
            'lock_token' => $claimPayload['lock_token'] ?? null,
            'fencing_token' => $claimPayload['fencing_token'] ?? null,
            'job_type' => 'extract_facts',
            'result' => ['facts_patch' => ['duration_ms' => 99]],
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'job-submit-binding-intruder',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_LOCKED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('LOCK_INVALID', $payload['code'] ?? null);
    }

    private function seedJob(string $jobId, string $jobType = 'extract_facts'): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->ensureJobSchema($connection);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $assetUuid = 'asset-'.$jobId;

        if ((int) $connection->fetchOne('SELECT COUNT(*) FROM asset WHERE uuid = :uuid', ['uuid' => $assetUuid]) === 0) {
            $connection->insert('asset', [
                'uuid' => $assetUuid,
                'media_type' => 'VIDEO',
                'filename' => $jobId.'.mov',
                'state' => 'READY',
                'tags' => '[]',
                'notes' => null,
                'fields' => json_encode([
                    'paths' => [
                        'storage_id' => 'nas-main',
                        'original_relative' => 'INBOX/'.$jobId.'.mov',
                        'sidecars_relative' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $connection->insert('processing_job', [
            'id' => $jobId,
            'asset_uuid' => $assetUuid,
            'job_type' => $jobType,
            'status' => 'pending',
            'claimed_by' => null,
            'lock_token' => null,
            'fencing_token' => null,
            'locked_until' => null,
            'result_payload' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function ensureJobSchema(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS asset (
                uuid VARCHAR(36) PRIMARY KEY NOT NULL,
                media_type VARCHAR(16) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                state VARCHAR(32) NOT NULL,
                tags CLOB NOT NULL,
                notes CLOB DEFAULT NULL,
                fields CLOB NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )'
        );
        $this->ensureProcessingJobTable($connection);
        $this->ensureOperationLockTable($connection);
        $this->ensureIdempotencyTable($connection);
    }

    private function bootClient(): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->disableReboot();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->ensureAuthClientTables($connection);
        $this->ensureUserAuthSessionTable($connection);
        $this->ensureUserTwoFactorStateTable($connection);
        $this->ensureAgentSignatureTables($connection);
        $connection->executeStatement('DELETE FROM auth_client_access_token');
        $connection->executeStatement('DELETE FROM auth_device_flow');
        $connection->executeStatement('DELETE FROM auth_mcp_challenge');
        $connection->executeStatement('DELETE FROM user_auth_session');
        $connection->executeStatement('DELETE FROM user_two_factor_state');
        $connection->executeStatement('DELETE FROM agent_public_key');
        $connection->executeStatement('DELETE FROM agent_signature_nonce');
        $cache = self::getContainer()->get('cache.app');
        if (method_exists($cache, 'clear')) {
            $cache->clear();
        }

        return $client;
    }

    private function loginAgent(KernelBrowser $client): void
    {
        $this->loginAgentAs($client, 'agent@retaia.local');
    }

    private function loginAgentAs(KernelBrowser $client, string $email): void
    {
        $this->insertAgent($email, ['ROLE_AGENT']);
        $this->loginAs($client, $email);
        $this->registerDefaultAgent($client);
        $this->attachDefaultAgentSignatureHeaders($client);
    }

    private function loginAs(KernelBrowser $client, string $email): void
    {
        $this->authenticateClient($client, $email);
    }

    /**
     * @param array<int, string> $roles
     */
    private function insertAgent(string $email, array $roles): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $existing = $connection->fetchOne('SELECT COUNT(*) FROM app_user WHERE email = :email', ['email' => $email]);
        if ((int) $existing > 0) {
            return;
        }

        $connection->insert('app_user', [
            'id' => bin2hex(random_bytes(16)),
            'email' => $email,
            'password_hash' => password_hash('change-me', PASSWORD_DEFAULT),
            'roles' => json_encode($roles, JSON_THROW_ON_ERROR),
            'email_verified' => 1,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
