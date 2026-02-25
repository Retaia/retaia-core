<?php

namespace App\Tests\Functional;

use App\Asset\AssetState;
use App\Entity\Asset;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class WorkflowApiTest extends WebTestCase
{
    use RecreateDatabaseTrait;

    public function testMovePreviewAndApplyTransitionsAssets(): void
    {
        $client = $this->createAuthenticatedClient('admin@retaia.local');
        $this->seedAsset('11111111-aaaa-4aaa-8aaa-111111111111', AssetState::DECIDED_KEEP, 'file-a.mov');
        $this->seedAsset('22222222-bbbb-4bbb-8bbb-222222222222', AssetState::DECIDED_REJECT, 'file-b.mov');

        $client->jsonRequest('POST', '/api/v1/batches/moves/preview', [
            'uuids' => ['11111111-aaaa-4aaa-8aaa-111111111111', '22222222-bbbb-4bbb-8bbb-222222222222'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $preview = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(2, $preview['eligible_count'] ?? null);

        $client->jsonRequest('POST', '/api/v1/batches/moves', [
            'uuids' => ['11111111-aaaa-4aaa-8aaa-111111111111', '22222222-bbbb-4bbb-8bbb-222222222222'],
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'batch-move-1',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $apply = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(2, $apply['success_count'] ?? null);
        $batchId = (string) ($apply['batch_id'] ?? '');
        self::assertNotSame('', $batchId);

        $client->request('GET', '/api/v1/batches/moves/'.$batchId);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->request('GET', '/api/v1/assets/11111111-aaaa-4aaa-8aaa-111111111111');
        $keepAsset = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('ARCHIVED', $keepAsset['summary']['state'] ?? null);

        $client->request('GET', '/api/v1/assets/22222222-bbbb-4bbb-8bbb-222222222222');
        $rejectAsset = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('REJECTED', $rejectAsset['summary']['state'] ?? null);
    }

    public function testGetAssetReturnsSpecDetailStructure(): void
    {
        $client = $this->createAuthenticatedClient('admin@retaia.local');
        $uuid = '77777777-1111-4111-8111-777777777777';
        $this->seedAsset($uuid, AssetState::DECISION_PENDING, 'detail.mov');

        $client->request('GET', '/api/v1/assets/'.$uuid);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);

        self::assertIsArray($payload['summary'] ?? null);
        self::assertSame($uuid, $payload['summary']['uuid'] ?? null);
        self::assertSame('DECISION_PENDING', $payload['summary']['state'] ?? null);
        self::assertArrayHasKey('created_at', $payload['summary']);
        self::assertArrayNotHasKey('filename', $payload['summary']);

        self::assertIsArray($payload['paths'] ?? null);
        self::assertSame('nas-main', $payload['paths']['storage_id'] ?? null);
        self::assertSame('INBOX/detail.mov', $payload['paths']['original_relative'] ?? null);
        self::assertIsArray($payload['paths']['sidecars_relative'] ?? null);

        self::assertIsArray($payload['processing'] ?? null);
        self::assertArrayHasKey('facts_done', $payload['processing']);
        self::assertArrayHasKey('proxy_done', $payload['processing']);
        self::assertArrayHasKey('thumbs_done', $payload['processing']);
        self::assertArrayHasKey('waveform_done', $payload['processing']);

        self::assertIsArray($payload['derived'] ?? null);
        self::assertArrayHasKey('thumbs', $payload['derived']);
        self::assertIsArray($payload['derived']['thumbs'] ?? null);

        self::assertIsArray($payload['transcript'] ?? null);
        self::assertSame('NONE', $payload['transcript']['status'] ?? null);

        self::assertIsArray($payload['decisions'] ?? null);
        self::assertArrayHasKey('history', $payload['decisions']);
        self::assertIsArray($payload['decisions']['history'] ?? null);

        self::assertIsArray($payload['audit'] ?? null);
        self::assertArrayHasKey('path_history', $payload['audit']);
        self::assertIsArray($payload['audit']['path_history'] ?? null);
    }

    public function testBulkDecisionsEndpointsAreForbiddenWhenFeatureIsDisabled(): void
    {
        $client = $this->createAuthenticatedClient('admin@retaia.local');
        $this->seedAsset('33333333-cccc-4ccc-8ccc-333333333333', AssetState::DECISION_PENDING, 'decision.mov');

        $client->jsonRequest('POST', '/api/v1/decisions/preview', [
            'action' => 'KEEP',
            'uuids' => ['33333333-cccc-4ccc-8ccc-333333333333'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $preview = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_SCOPE', $preview['code'] ?? null);

        $client->jsonRequest('POST', '/api/v1/decisions/apply', [
            'action' => 'KEEP',
            'uuids' => ['33333333-cccc-4ccc-8ccc-333333333333'],
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'bulk-decisions-1',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $apply = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_SCOPE', $apply['code'] ?? null);
    }

    public function testPurgePreviewAndApply(): void
    {
        $client = $this->createAuthenticatedClient('admin@retaia.local');
        $this->seedAsset('44444444-dddd-4ddd-8ddd-444444444444', AssetState::REJECTED, 'purge.mov');
        $this->seedAsset('55555555-eeee-4eee-8eee-555555555555', AssetState::ARCHIVED, 'archive.mov');

        $client->jsonRequest('POST', '/api/v1/assets/44444444-dddd-4ddd-8ddd-444444444444/purge/preview');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $preview = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(true, $preview['allowed'] ?? null);

        $client->jsonRequest('POST', '/api/v1/assets/44444444-dddd-4ddd-8ddd-444444444444/purge', [], [
            'HTTP_IDEMPOTENCY_KEY' => 'purge-1',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('PURGED', $payload['state'] ?? null);

        $client->jsonRequest('POST', '/api/v1/assets/55555555-eeee-4eee-8eee-555555555555/purge', [], [
            'HTTP_IDEMPOTENCY_KEY' => 'purge-2',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testAgentCannotRunHumanWorkflowEndpoints(): void
    {
        $client = $this->createAuthenticatedClient('agent@retaia.local');

        $client->jsonRequest('POST', '/api/v1/batches/moves/preview', []);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_ACTOR', $payload['code'] ?? null);
    }

    public function testPurgeReturnsConflictWhenAssetHasActiveOperationLock(): void
    {
        $client = $this->createAuthenticatedClient('admin@retaia.local');
        $uuid = '66666666-ffff-4fff-8fff-666666666666';
        $this->seedAsset($uuid, AssetState::REJECTED, 'locked-purge.mov');

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->insert('asset_operation_lock', [
            'id' => bin2hex(random_bytes(16)),
            'asset_uuid' => $uuid,
            'lock_type' => 'asset_purge_lock',
            'actor_id' => 'test',
            'acquired_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'released_at' => null,
        ]);

        $client->jsonRequest('POST', '/api/v1/assets/'.$uuid.'/purge', [], [
            'HTTP_IDEMPOTENCY_KEY' => 'purge-locked-1',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('STATE_CONFLICT', $payload['code'] ?? null);
    }

    private function createAuthenticatedClient(string $email): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureWorkflowSchema();

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $token = $payload['access_token'] ?? null;
        self::assertIsString($token);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        return $client;
    }

    private function seedAsset(string $uuid, AssetState $state, string $filename): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        if ($entityManager->find(Asset::class, $uuid) instanceof Asset) {
            return;
        }

        $asset = new Asset($uuid, 'VIDEO', $filename, $state);
        $entityManager->persist($asset);
        $entityManager->flush();
    }

    private function ensureWorkflowSchema(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS batch_move_report (batch_id VARCHAR(16) PRIMARY KEY NOT NULL, payload CLOB NOT NULL, created_at DATETIME NOT NULL)');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS asset_operation_lock (id VARCHAR(32) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, lock_type VARCHAR(32) NOT NULL, actor_id VARCHAR(64) NOT NULL, acquired_at DATETIME NOT NULL, released_at DATETIME DEFAULT NULL)');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS processing_job (id VARCHAR(36) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, job_type VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, claimed_by VARCHAR(32) DEFAULT NULL, lock_token VARCHAR(64) DEFAULT NULL, locked_until DATETIME DEFAULT NULL, result_payload CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS idempotency_entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, actor_id VARCHAR(64) NOT NULL, method VARCHAR(8) NOT NULL, path VARCHAR(255) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, request_hash VARCHAR(64) NOT NULL, response_status INTEGER NOT NULL, response_body CLOB NOT NULL, created_at DATETIME NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_idempotency_key_scope ON idempotency_entry (actor_id, method, path, idempotency_key)');
    }
}
