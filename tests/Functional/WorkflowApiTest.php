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
        self::assertSame('ARCHIVED', $keepAsset['state'] ?? null);

        $client->request('GET', '/api/v1/assets/22222222-bbbb-4bbb-8bbb-222222222222');
        $rejectAsset = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('REJECTED', $rejectAsset['state'] ?? null);
    }

    public function testBulkDecisionsPreviewAndApply(): void
    {
        $client = $this->createAuthenticatedClient('admin@retaia.local');
        $this->seedAsset('33333333-cccc-4ccc-8ccc-333333333333', AssetState::DECISION_PENDING, 'decision.mov');
        $client->jsonRequest('POST', '/api/v1/decisions/preview', [
            'action' => 'KEEP',
            'uuids' => ['33333333-cccc-4ccc-8ccc-333333333333'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $preview = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $preview['eligible_count'] ?? null);

        $client->jsonRequest('POST', '/api/v1/decisions/apply', [
            'action' => 'KEEP',
            'uuids' => ['33333333-cccc-4ccc-8ccc-333333333333'],
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'bulk-decisions-1',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->request('GET', '/api/v1/assets/33333333-cccc-4ccc-8ccc-333333333333');
        $asset = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('DECIDED_KEEP', $asset['state'] ?? null);
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
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS idempotency_entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, actor_id VARCHAR(64) NOT NULL, method VARCHAR(8) NOT NULL, path VARCHAR(255) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, request_hash VARCHAR(64) NOT NULL, response_status INTEGER NOT NULL, response_body CLOB NOT NULL, created_at DATETIME NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_idempotency_key_scope ON idempotency_entry (actor_id, method, path, idempotency_key)');
    }
}
