<?php

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class JobApiTest extends WebTestCase
{
    use RecreateDatabaseTrait;

    public function testClaimIsAtomicWithSingleWinner(): void
    {
        $clientA = $this->bootClient();
        $this->seedJob('job-1');
        $this->loginAgent($clientA);

        $clientA->jsonRequest('POST', '/api/v1/jobs/job-1/claim');
        $firstStatus = $clientA->getResponse()->getStatusCode();
        $clientA->jsonRequest('POST', '/api/v1/jobs/job-1/claim');
        $secondStatus = $clientA->getResponse()->getStatusCode();

        $statusCodes = [$firstStatus, $secondStatus];
        sort($statusCodes);

        self::assertSame([Response::HTTP_OK, Response::HTTP_CONFLICT], $statusCodes);
    }

    public function testLeaseExpiryMakesJobClaimableAgain(): void
    {
        $first = $this->bootClient();
        $this->seedJob('job-2');
        $this->loginAgent($first);

        $first->jsonRequest('POST', '/api/v1/jobs/job-2/claim');
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

        $first->jsonRequest('POST', '/api/v1/jobs/job-2/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testHeartbeatExtendsLock(): void
    {
        $client = $this->bootClient();
        $this->seedJob('job-3');
        $this->loginAgent($client);

        $client->jsonRequest('POST', '/api/v1/jobs/job-3/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $claimPayload = json_decode((string) $client->getResponse()->getContent(), true);

        $client->jsonRequest('POST', '/api/v1/jobs/job-3/heartbeat', [
            'lock_token' => $claimPayload['lock_token'] ?? null,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $heartbeatPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(
            strtotime((string) ($claimPayload['locked_until'] ?? '1970-01-01T00:00:00Z')),
            strtotime((string) ($heartbeatPayload['locked_until'] ?? '1970-01-01T00:00:00Z')),
        );
    }

    public function testSubmitIsIdempotentWithSamePayloadAndConflictsOnDifferentPayload(): void
    {
        $client = $this->bootClient();
        $this->seedJob('job-4');
        $this->loginAgent($client);

        $client->jsonRequest('POST', '/api/v1/jobs/job-4/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $claimPayload = json_decode((string) $client->getResponse()->getContent(), true);
        $lockToken = $claimPayload['lock_token'] ?? null;
        self::assertIsString($lockToken);

        $client->jsonRequest('POST', '/api/v1/jobs/job-4/submit', [
            'lock_token' => $lockToken,
            'result' => ['processed' => true],
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'idempo-job-4',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $firstPayload = json_decode((string) $client->getResponse()->getContent(), true);

        $client->jsonRequest('POST', '/api/v1/jobs/job-4/submit', [
            'lock_token' => $lockToken,
            'result' => ['processed' => true],
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'idempo-job-4',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $secondPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($firstPayload, $secondPayload);

        $client->jsonRequest('POST', '/api/v1/jobs/job-4/submit', [
            'lock_token' => $lockToken,
            'result' => ['processed' => false],
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
    }

    public function testSubmitRejectsMissingIdempotencyKeyAndMissingLockToken(): void
    {
        $client = $this->bootClient();
        $this->seedJob('job-submit-validation');
        $this->loginAgent($client);

        $client->jsonRequest('POST', '/api/v1/jobs/job-submit-validation/submit', [
            'lock_token' => 'any-lock',
            'result' => ['ok' => true],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $missingKey = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('MISSING_IDEMPOTENCY_KEY', $missingKey['code'] ?? null);

        $client->jsonRequest('POST', '/api/v1/jobs/job-submit-validation/submit', [
            'result' => ['ok' => true],
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'submit-missing-lock',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $missingLock = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $missingLock['code'] ?? null);
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
        $client->jsonRequest('POST', '/api/v1/jobs/'.$jobId.'/claim');
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

        $client->jsonRequest('POST', '/api/v1/jobs/job-fail-1/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $claimPayload = json_decode((string) $client->getResponse()->getContent(), true);
        $lockToken = (string) ($claimPayload['lock_token'] ?? '');
        self::assertNotSame('', $lockToken);

        $client->jsonRequest('POST', '/api/v1/jobs/job-fail-1/fail', [
            'lock_token' => $lockToken,
            'error_code' => 'ERR_GENERIC',
            'message' => 'failed',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $missingKey = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('MISSING_IDEMPOTENCY_KEY', $missingKey['code'] ?? null);

        $client->jsonRequest('POST', '/api/v1/jobs/job-fail-1/fail', [
            'lock_token' => $lockToken,
            'error_code' => 'ERR_GENERIC',
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'job-fail-validation',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $validationPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $validationPayload['code'] ?? null);

        $client->jsonRequest('POST', '/api/v1/jobs/job-fail-2/fail', [
            'lock_token' => 'wrong-lock',
            'error_code' => 'ERR_GENERIC',
            'message' => 'failed',
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'job-fail-conflict',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $conflictPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('STATE_CONFLICT', $conflictPayload['code'] ?? null);

        $client->jsonRequest('POST', '/api/v1/jobs/job-fail-1/fail', [
            'lock_token' => $lockToken,
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

    private function seedJob(string $jobId): void
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
                'fields' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $connection->insert('processing_job', [
            'id' => $jobId,
            'asset_uuid' => $assetUuid,
            'job_type' => 'extract_facts',
            'status' => 'pending',
            'claimed_by' => null,
            'lock_token' => null,
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
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS processing_job (
                id VARCHAR(36) PRIMARY KEY NOT NULL,
                asset_uuid VARCHAR(36) NOT NULL,
                job_type VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                claimed_by VARCHAR(32) DEFAULT NULL,
                lock_token VARCHAR(64) DEFAULT NULL,
                locked_until DATETIME DEFAULT NULL,
                result_payload CLOB DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )'
        );
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS asset_operation_lock (
                id VARCHAR(32) PRIMARY KEY NOT NULL,
                asset_uuid VARCHAR(36) NOT NULL,
                lock_type VARCHAR(32) NOT NULL,
                actor_id VARCHAR(64) NOT NULL,
                acquired_at DATETIME NOT NULL,
                released_at DATETIME DEFAULT NULL
            )'
        );

        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS idempotency_entry (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                actor_id VARCHAR(64) NOT NULL,
                method VARCHAR(8) NOT NULL,
                path VARCHAR(255) NOT NULL,
                idempotency_key VARCHAR(128) NOT NULL,
                request_hash VARCHAR(64) NOT NULL,
                response_status INTEGER NOT NULL,
                response_body CLOB NOT NULL,
                created_at DATETIME NOT NULL
            )'
        );
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_idempotency_key_scope ON idempotency_entry (actor_id, method, path, idempotency_key)');
    }

    private function bootClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function loginAgent(KernelBrowser $client): void
    {
        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'agent@retaia.local',
            'password' => 'change-me',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }
}
