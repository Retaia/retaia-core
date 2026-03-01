<?php

namespace App\Tests\Functional;

use App\Asset\AssetState;
use App\Entity\Asset;
use App\Tests\Support\ApiAuthClientTrait;
use App\Tests\Support\FunctionalSchemaTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class WorkflowApiTest extends WebTestCase
{
    use RecreateDatabaseTrait;
    use ApiAuthClientTrait;
    use FunctionalSchemaTrait;

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

    public function testOpsIngestDiagnosticsReturnsSnapshotForAdmin(): void
    {
        $client = $this->createAuthenticatedClient('admin@retaia.local');

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->insert('ingest_scan_file', [
            'path' => 'INBOX/q1.mov',
            'size_bytes' => 100,
            'mtime' => '2026-02-10 12:00:00',
            'stable_count' => 2,
            'status' => 'queued',
            'first_seen_at' => '2026-02-10 12:00:00',
            'last_seen_at' => '2026-02-10 12:01:00',
        ]);
        $connection->insert('ingest_scan_file', [
            'path' => 'INBOX/m1.mov',
            'size_bytes' => 100,
            'mtime' => '2026-02-10 12:00:00',
            'stable_count' => 2,
            'status' => 'missing',
            'first_seen_at' => '2026-02-10 12:00:00',
            'last_seen_at' => '2026-02-10 12:01:00',
        ]);
        $connection->insert('ingest_unmatched_sidecar', [
            'path' => 'INBOX/a.lrf',
            'reason' => 'missing_parent',
            'detected_at' => '2026-02-10 12:02:00',
        ]);
        $connection->insert('ingest_unmatched_sidecar', [
            'path' => 'INBOX/b.xmp',
            'reason' => 'ambiguous_parent',
            'detected_at' => '2026-02-10 12:03:00',
        ]);

        $client->request('GET', '/api/v1/ops/ingest/diagnostics');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $payload['queued'] ?? null);
        self::assertSame(1, $payload['missing'] ?? null);
        self::assertSame(2, $payload['unmatched_sidecars'] ?? null);
        self::assertIsArray($payload['latest_unmatched'] ?? null);
        self::assertSame('INBOX/b.xmp', $payload['latest_unmatched'][0]['path'] ?? null);
        self::assertSame('ambiguous_parent', $payload['latest_unmatched'][0]['reason'] ?? null);
    }

    public function testOpsIngestDiagnosticsIsForbiddenForAgentActor(): void
    {
        $client = $this->createAuthenticatedClient('agent@retaia.local');

        $client->request('GET', '/api/v1/ops/ingest/diagnostics');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_ACTOR', $payload['code'] ?? null);
    }

    public function testOpsReadinessReturnsSnapshotForAdmin(): void
    {
        $root = sys_get_temp_dir().'/retaia-readiness-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';

        $client = $this->createAuthenticatedClient('admin@retaia.local');
        $client->request('GET', '/api/v1/ops/readiness');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($payload);
        self::assertSame('ok', $payload['status'] ?? null);
        self::assertIsArray($payload['checks'] ?? null);
        self::assertNotEmpty($payload['checks']);
        $checkNames = array_map(static fn (array $check): string => (string) ($check['name'] ?? ''), $payload['checks']);
        self::assertContains('database', $checkNames);
    }

    public function testOpsReadinessReturnsDegradedWhenCriticalFoldersAreMissing(): void
    {
        $root = sys_get_temp_dir().'/retaia-readiness-degraded-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';

        $client = $this->createAuthenticatedClient('admin@retaia.local');
        $client->request('GET', '/api/v1/ops/readiness');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($payload);
        self::assertSame('degraded', $payload['status'] ?? null);
        self::assertIsArray($payload['checks'] ?? null);
        $ingestCheck = null;
        foreach ($payload['checks'] as $check) {
            if (($check['name'] ?? null) === 'ingest_watch_path') {
                $ingestCheck = $check;
                break;
            }
        }
        self::assertIsArray($ingestCheck);
        self::assertSame('fail', $ingestCheck['status'] ?? null);
    }

    public function testOpsMissingEndpointsReturnExpectedPayloads(): void
    {
        $client = $this->createAuthenticatedClient('admin@retaia.local');
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('asset_operation_lock', [
            'id' => bin2hex(random_bytes(16)),
            'asset_uuid' => 'lock-asset-1',
            'lock_type' => 'asset_move_lock',
            'actor_id' => 'ops-admin',
            'acquired_at' => '2026-02-10 10:00:00',
            'released_at' => null,
        ]);
        $connection->insert('asset_operation_lock', [
            'id' => bin2hex(random_bytes(16)),
            'asset_uuid' => 'lock-asset-2',
            'lock_type' => 'asset_purge_lock',
            'actor_id' => 'ops-admin',
            'acquired_at' => '2026-02-10 10:01:00',
            'released_at' => null,
        ]);
        $connection->insert('processing_job', [
            'id' => 'job-pending-ops',
            'asset_uuid' => 'job-asset-1',
            'job_type' => 'extract_facts',
            'status' => 'pending',
            'claimed_by' => null,
            'lock_token' => null,
            'locked_until' => null,
            'result_payload' => null,
            'created_at' => '2026-02-10 09:59:00',
            'updated_at' => '2026-02-10 09:59:00',
        ]);
        $connection->insert('processing_job', [
            'id' => 'job-claimed-ops',
            'asset_uuid' => 'job-asset-2',
            'job_type' => 'extract_facts',
            'status' => 'claimed',
            'claimed_by' => 'agent-1',
            'lock_token' => 'token-1',
            'locked_until' => '2026-02-10 11:00:00',
            'result_payload' => null,
            'created_at' => '2026-02-10 10:00:00',
            'updated_at' => '2026-02-10 10:00:00',
        ]);
        $connection->insert('processing_job', [
            'id' => 'job-failed-ops',
            'asset_uuid' => 'job-asset-3',
            'job_type' => 'generate_proxy',
            'status' => 'failed',
            'claimed_by' => null,
            'lock_token' => null,
            'locked_until' => null,
            'result_payload' => null,
            'created_at' => '2026-02-10 10:01:00',
            'updated_at' => '2026-02-10 10:01:00',
        ]);
        $connection->insert('ingest_unmatched_sidecar', [
            'path' => 'INBOX/unmatched-1.xmp',
            'reason' => 'missing_parent',
            'detected_at' => '2026-02-10 12:00:00',
        ]);
        $connection->insert('ingest_unmatched_sidecar', [
            'path' => 'INBOX/unmatched-2.srt',
            'reason' => 'ambiguous_parent',
            'detected_at' => '2026-02-10 12:10:00',
        ]);

        $client->request('GET', '/api/v1/ops/locks?limit=1&offset=0');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $locks = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(2, $locks['total'] ?? null);
        self::assertCount(1, $locks['items'] ?? []);
        self::assertSame('lock-asset-2', $locks['items'][0]['asset_uuid'] ?? null);

        $client->request('GET', '/api/v1/ops/locks?limit=1&offset=1');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $locksPage2 = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(2, $locksPage2['total'] ?? null);
        self::assertCount(1, $locksPage2['items'] ?? []);
        self::assertSame('lock-asset-1', $locksPage2['items'][0]['asset_uuid'] ?? null);

        $client->jsonRequest('POST', '/api/v1/ops/locks/recover', [
            'stale_lock_minutes' => 1,
            'dry_run' => true,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $recover = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(true, $recover['dry_run'] ?? null);
        self::assertGreaterThanOrEqual(1, (int) ($recover['stale_examined'] ?? 0));
        self::assertSame(0, $recover['recovered'] ?? null);

        $client->jsonRequest('POST', '/api/v1/ops/locks/recover', [
            'stale_lock_minutes' => 0,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $recoverInvalidMinutes = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $recoverInvalidMinutes['code'] ?? null);

        $client->jsonRequest('POST', '/api/v1/ops/locks/recover', [
            'stale_lock_minutes' => '30',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $client->jsonRequest('POST', '/api/v1/ops/locks/recover', [
            'dry_run' => 'true',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $client->request('GET', '/api/v1/ops/jobs/queue');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $queue = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $queue['summary']['pending_total'] ?? null);
        self::assertSame(1, $queue['summary']['claimed_total'] ?? null);
        self::assertSame(1, $queue['summary']['failed_total'] ?? null);
        self::assertNotEmpty($queue['by_type'] ?? []);

        $client->request('GET', '/api/v1/ops/ingest/unmatched?reason=missing_parent&limit=10');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $unmatched = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $unmatched['total'] ?? null);
        self::assertSame('INBOX/unmatched-1.xmp', $unmatched['items'][0]['path'] ?? null);

        $client->request('GET', '/api/v1/ops/ingest/unmatched?reason=invalid_reason');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $invalidReason = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $invalidReason['code'] ?? null);

        $client->request('GET', '/api/v1/ops/ingest/unmatched?since=not-a-date');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $invalidSince = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $invalidSince['code'] ?? null);
    }

    public function testOpsIngestRequeueEnqueuesJobsForAssetTarget(): void
    {
        $client = $this->createAuthenticatedClient('admin@retaia.local');
        $uuid = 'abababab-1234-4abc-8abc-1234567890ab';
        $this->seedAsset($uuid, AssetState::READY, 'requeue.mov');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $asset = $entityManager->find(Asset::class, $uuid);
        self::assertInstanceOf(Asset::class, $asset);
        $asset->setFields([
            'proxy_done' => true,
            'paths' => [
                'original_relative' => 'INBOX/requeue.mov',
                'sidecars_relative' => [],
            ],
        ]);
        $entityManager->flush();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_processing_job_asset_type ON processing_job (asset_uuid, job_type)');
        $connection->insert('processing_job', [
            'id' => 'requeue-existing-1',
            'asset_uuid' => $uuid,
            'job_type' => 'extract_facts',
            'status' => 'pending',
            'claimed_by' => null,
            'lock_token' => null,
            'locked_until' => null,
            'result_payload' => null,
            'created_at' => '2026-02-10 09:59:00',
            'updated_at' => '2026-02-10 09:59:00',
        ]);

        $client->jsonRequest('POST', '/api/v1/ops/ingest/requeue', [
            'asset_uuid' => $uuid,
            'include_derived' => true,
            'reason' => 'manual_recovery',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(true, $payload['accepted'] ?? null);
        self::assertSame($uuid, $payload['target']['asset_uuid'] ?? null);
        self::assertSame(1, $payload['requeued_assets'] ?? null);
        self::assertSame(1, $payload['requeued_jobs'] ?? null);
        self::assertSame(1, $payload['deduplicated_jobs'] ?? null);
    }

    public function testOpsIngestRequeueValidatesPayload(): void
    {
        $client = $this->createAuthenticatedClient('admin@retaia.local');

        $client->jsonRequest('POST', '/api/v1/ops/ingest/requeue', [
            'asset_uuid' => '11111111-1111-4111-8111-111111111111',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $missingReason = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $missingReason['code'] ?? null);

        $client->jsonRequest('POST', '/api/v1/ops/ingest/requeue', [
            'path' => '../unsafe.mov',
            'reason' => 'manual_recovery',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $unsafePath = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $unsafePath['code'] ?? null);

        $client->jsonRequest('POST', '/api/v1/ops/ingest/requeue', [
            'asset_uuid' => '11111111-1111-4111-8111-111111111111',
            'reason' => 'manual_recovery',
            'include_sidecars' => 'yes',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $invalidSidecars = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $invalidSidecars['code'] ?? null);
    }

    public function testOpsEndpointsRequireAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/ops/ingest/diagnostics');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('UNAUTHORIZED', $payload['code'] ?? null);

        $client->request('GET', '/api/v1/ops/readiness');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('GET', '/api/v1/ops/locks');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('POST', '/api/v1/ops/locks/recover', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('GET', '/api/v1/ops/jobs/queue');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('GET', '/api/v1/ops/ingest/unmatched');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
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

    public function testPurgeDeletesDerivedFilesAndRows(): void
    {
        $root = sys_get_temp_dir().'/retaia-purge-derived-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS/.derived/99999999-9999-4999-8999-999999999999', 0777, true);
        file_put_contents($root.'/REJECTS/purge-derived.mov', 'origin');
        file_put_contents($root.'/REJECTS/.derived/99999999-9999-4999-8999-999999999999/proxy.mp4', 'derived');
        file_put_contents($root.'/REJECTS/purge-derived.srt', 'subtitle');
        $_ENV['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';
        $_SERVER['APP_INGEST_WATCH_PATH'] = $root.'/INBOX';

        $client = $this->createAuthenticatedClient('admin@retaia.local');
        $uuid = '99999999-9999-4999-8999-999999999999';
        $this->seedAsset($uuid, AssetState::REJECTED, 'purge-derived.mov');

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $asset = $entityManager->find(Asset::class, $uuid);
        self::assertInstanceOf(Asset::class, $asset);
        $asset->setFields([
            'source_path' => 'INBOX/purge-derived.mov',
            'current_path' => 'REJECTS/purge-derived.mov',
            'paths' => [
                'original_relative' => 'REJECTS/purge-derived.mov',
                'sidecars_relative' => [
                    'REJECTS/.derived/99999999-9999-4999-8999-999999999999/proxy.mp4',
                    'REJECTS/purge-derived.srt',
                ],
            ],
        ]);
        $entityManager->flush();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->insert('asset_derived_file', [
            'id' => bin2hex(random_bytes(8)),
            'asset_uuid' => $uuid,
            'kind' => 'proxy_video',
            'content_type' => 'video/mp4',
            'size_bytes' => 7,
            'sha256' => null,
            'storage_path' => 'REJECTS/.derived/99999999-9999-4999-8999-999999999999/proxy.mp4',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $client->jsonRequest('POST', '/api/v1/assets/'.$uuid.'/purge', [], [
            'HTTP_IDEMPOTENCY_KEY' => 'purge-derived-1',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        self::assertFileDoesNotExist($root.'/REJECTS/purge-derived.mov');
        self::assertFileDoesNotExist($root.'/REJECTS/purge-derived.srt');
        self::assertFileDoesNotExist($root.'/REJECTS/.derived/99999999-9999-4999-8999-999999999999/proxy.mp4');
        $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM asset_derived_file WHERE asset_uuid = :assetUuid', ['assetUuid' => $uuid]);
        self::assertSame(0, $count);
    }

    private function createAuthenticatedClient(string $email): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureWorkflowSchema();

        $this->authenticateClient($client, $email);

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
        $this->ensureOperationLockTable($connection);
        $this->ensureProcessingJobTable($connection);
        $this->ensureIngestScanTable($connection);
        $this->ensureUnmatchedSidecarTable($connection);
        $this->ensureAssetDerivedFileTable($connection);
        $this->ensureIdempotencyTable($connection);
    }
}
