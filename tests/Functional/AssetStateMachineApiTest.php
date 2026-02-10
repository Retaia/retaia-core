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

final class AssetStateMachineApiTest extends WebTestCase
{
    use RecreateDatabaseTrait;

    public function testDecisionTransitionWorksFromDecisionPendingToKeep(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('POST', '/api/v1/assets/11111111-1111-1111-1111-111111111111/decision', [
            'action' => 'KEEP',
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'asset-decision-ok-1',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('DECIDED_KEEP', $payload['state'] ?? null);
    }

    public function testDecisionTransitionReturns409WhenForbidden(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('POST', '/api/v1/assets/22222222-2222-2222-2222-222222222222/decision', [
            'action' => 'KEEP',
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'asset-decision-conflict-1',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('STATE_CONFLICT', $payload['code'] ?? null);
    }

    public function testReopenFromArchivedTransitionsToDecisionPending(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('POST', '/api/v1/assets/33333333-3333-3333-3333-333333333333/reopen');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('DECISION_PENDING', $payload['state'] ?? null);
    }

    public function testReprocessTransitionsArchivedAssetBackToReady(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('POST', '/api/v1/assets/33333333-3333-3333-3333-333333333333/reprocess', [], [
            'HTTP_IDEMPOTENCY_KEY' => 'asset-reprocess-ok-1',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('READY', $payload['state'] ?? null);
    }

    public function testReprocessReturnsNotFoundForUnknownAsset(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('POST', '/api/v1/assets/00000000-0000-0000-0000-000000000000/reprocess', [], [
            'HTTP_IDEMPOTENCY_KEY' => 'asset-reprocess-missing-1',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('NOT_FOUND', $payload['code'] ?? null);
    }

    public function testPatchUpdatesTagsNotesAndFields(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('PATCH', '/api/v1/assets/11111111-1111-1111-1111-111111111111', [
            'tags' => ['updated', 'updated', 'news'],
            'notes' => 'patched note',
            'fields' => ['camera' => 'fx3', 'fps' => 25],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['updated', 'news'], $payload['tags'] ?? []);
        self::assertSame('patched note', $payload['notes'] ?? null);
        self::assertSame('fx3', $payload['fields']['camera'] ?? null);
    }

    public function testPatchReturnsNotFoundForUnknownAsset(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('PATCH', '/api/v1/assets/00000000-0000-0000-0000-000000000000', [
            'notes' => 'anything',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('NOT_FOUND', $payload['code'] ?? null);
    }

    public function testPatchReturnsGoneForPurgedAsset(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->seedPurgedAsset();

        $client->jsonRequest('PATCH', '/api/v1/assets/44444444-4444-4444-4444-444444444444', [
            'notes' => 'should fail',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('STATE_CONFLICT', $payload['code'] ?? null);
    }

    public function testListAssetsFiltersByState(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->request('GET', '/api/v1/assets?state=PROCESSED&limit=10');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertCount(1, $payload['items'] ?? []);
        self::assertSame('22222222-2222-2222-2222-222222222222', $payload['items'][0]['uuid'] ?? null);
    }

    private function createAuthenticatedClient(bool $seedAssets = false): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureAuxiliaryTables();

        if ($seedAssets) {
            $this->seedAssets();
        }

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $client;
    }

    private function ensureAuxiliaryTables(): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS asset_operation_lock (id VARCHAR(32) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, lock_type VARCHAR(32) NOT NULL, actor_id VARCHAR(64) NOT NULL, acquired_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, released_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS idempotency_entry (id BIGSERIAL PRIMARY KEY, actor_id VARCHAR(64) NOT NULL, method VARCHAR(8) NOT NULL, path VARCHAR(255) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, request_hash VARCHAR(64) NOT NULL, response_status INTEGER NOT NULL, response_body TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_idempotency_key_scope ON idempotency_entry (actor_id, method, path, idempotency_key)');
    }

    private function seedAssets(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $asset1 = new Asset('11111111-1111-1111-1111-111111111111', 'VIDEO', 'rush-001.mov', AssetState::DECISION_PENDING);
        $asset1->setTags(['wedding']);
        $asset1->setNotes('first review');
        $asset1->setFields(['camera' => 'a7s']);

        $asset2 = new Asset('22222222-2222-2222-2222-222222222222', 'AUDIO', 'voice-001.wav', AssetState::PROCESSED);
        $asset3 = new Asset('33333333-3333-3333-3333-333333333333', 'PHOTO', 'archive-001.jpg', AssetState::ARCHIVED);

        $entityManager->persist($asset1);
        $entityManager->persist($asset2);
        $entityManager->persist($asset3);
        $entityManager->flush();
    }

    private function seedPurgedAsset(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        if ($entityManager->find(Asset::class, '44444444-4444-4444-4444-444444444444') instanceof Asset) {
            return;
        }

        $asset = new Asset('44444444-4444-4444-4444-444444444444', 'VIDEO', 'purged.mov', AssetState::PURGED);
        $entityManager->persist($asset);
        $entityManager->flush();
    }
}
