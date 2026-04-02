<?php

namespace App\Tests\Unit\Controller;

use App\Application\Auth\Port\AdminActorGateway;
use App\Application\Auth\ResolveAdminActorHandler;
use App\Controller\Api\OpsAdminAccessGuard;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Controller\Api\OpsIngestController;
use App\Entity\Asset;
use App\Ingest\Repository\IngestDiagnosticsRepository;
use App\Job\Repository\JobRepository;
use App\Storage\BusinessStorageRegistryInterface;
use App\Tests\Support\ProcessingJobSchemaTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class OpsIngestControllerTest extends TestCase
{    use ControllerInstantiationTrait;
    use ProcessingJobSchemaTrait;

    public function testDiagnosticsReturnsForbiddenWhenActorIsNotAdmin(): void
    {
        $controller = $this->controller(OpsIngestController::class, [
            'adminAccessGuard' => $this->forbiddenAdminGuard(),
        ]);

        self::assertSame(403, $controller->diagnostics()->getStatusCode());
    }

    public function testUnmatchedValidatesReason(): void
    {
        $controller = $this->controller(OpsIngestController::class, [
            'adminAccessGuard' => $this->allowAdminGuard(),
            'ingestDiagnostics' => new IngestDiagnosticsRepository($this->createStub(Connection::class)),
        ]);

        $response = $controller->unmatched(new Request(['reason' => 'invalid_reason']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['code'] ?? null);
    }

    public function testRequeueValidatesStorageForPathTarget(): void
    {
        $storageRegistry = $this->createStub(BusinessStorageRegistryInterface::class);
        $storageRegistry->method('has')->willReturn(false);

        $controller = $this->controller(OpsIngestController::class, [
            'adminAccessGuard' => $this->allowAdminGuard(),
            'storageRegistry' => $storageRegistry,
        ]);

        $request = Request::create('/', 'POST', [], [], [], [], json_encode([
            'path' => 'INBOX/requeue.mov',
            'storage_id' => 'unknown-storage',
            'reason' => 'manual_recovery',
        ], JSON_THROW_ON_ERROR));

        $response = $controller->requeue($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['code'] ?? null);
    }

    public function testRequeueAcceptsAssetTarget(): void
    {
        $asset = new Asset('abababab-1234-4abc-8abc-1234567890ab', 'VIDEO', 'demo.mov');
        $asset->setFields([
            'proxy_done' => true,
            'paths' => [
                'storage_id' => 'nas-main',
                'original_relative' => 'INBOX/demo.mov',
                'sidecars_relative' => [],
            ],
        ]);

        $assets = $this->createStub(AssetRepositoryInterface::class);
        $assets->method('findByUuid')->willReturn($asset);

        $storageRegistry = $this->createStub(BusinessStorageRegistryInterface::class);
        $storageRegistry->method('has')->willReturn(true);
        $jobs = $this->jobRepositoryWithExistingExtractFactsJob('abababab-1234-4abc-8abc-1234567890ab');

        $controller = $this->controller(OpsIngestController::class, [
            'adminAccessGuard' => $this->allowAdminGuard(),
            'assets' => $assets,
            'jobs' => $jobs,
            'storageRegistry' => $storageRegistry,
        ]);

        $request = Request::create('/', 'POST', [], [], [], [], json_encode([
            'asset_uuid' => 'abababab-1234-4abc-8abc-1234567890ab',
            'include_derived' => true,
            'reason' => 'manual_recovery',
        ], JSON_THROW_ON_ERROR));

        $response = $controller->requeue($request);
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('abababab-1234-4abc-8abc-1234567890ab', $payload['target']['asset_uuid'] ?? null);
        self::assertSame(1, $payload['requeued_assets'] ?? null);
        self::assertSame(1, $payload['requeued_jobs'] ?? null);
        self::assertSame(1, $payload['deduplicated_jobs'] ?? null);
    }

    private function jobRepositoryWithExistingExtractFactsJob(string $assetUuid): JobRepository
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createProcessingJobTable($connection, [
            'id_length' => 255,
            'state_version_length' => 255,
            'actor_length' => 255,
        ]);
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_processing_job_asset_type ON processing_job (asset_uuid, job_type)');
        $connection->insert('processing_job', [
            'id' => 'existing-extract-facts',
            'asset_uuid' => $assetUuid,
            'job_type' => 'extract_facts',
            'state_version' => '1',
            'status' => 'pending',
            'correlation_id' => null,
            'claimed_by' => null,
            'claimed_at' => null,
            'lock_token' => null,
            'fencing_token' => null,
            'locked_until' => null,
            'completed_by' => null,
            'completed_at' => null,
            'failed_by' => null,
            'failed_at' => null,
            'result_payload' => null,
            'created_at' => '2026-03-30 10:00:00',
            'updated_at' => '2026-03-30 10:00:00',
        ]);

        $storageRegistry = $this->createStub(BusinessStorageRegistryInterface::class);

        return new JobRepository($connection, $storageRegistry);
    }

    private function allowAdminGuard(): OpsAdminAccessGuard
    {
        $gateway = new class implements AdminActorGateway {
            public function isAdmin(): bool
            {
                return true;
            }

            public function actorId(): ?string
            {
                return 'admin-1';
            }
        };

        return new OpsAdminAccessGuard(new ResolveAdminActorHandler($gateway), $this->translatorStub());
    }

    private function forbiddenAdminGuard(): OpsAdminAccessGuard
    {
        $gateway = new class implements AdminActorGateway {
            public function isAdmin(): bool
            {
                return false;
            }

            public function actorId(): ?string
            {
                return null;
            }
        };

        return new OpsAdminAccessGuard(new ResolveAdminActorHandler($gateway), $this->translatorStub());
    }

}
