<?php

namespace App\Tests\Unit\Controller;

use App\Tests\Support\TranslatorStubTrait;
use App\Api\Service\AssetRequestPreconditionService;
use App\Application\Auth\Port\AgentActorGateway;
use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Workflow\ApplyDecisionsHandler;
use App\Application\Workflow\ApplyMovesHandler;
use App\Application\Workflow\CheckBulkDecisionsEnabledHandler;
use App\Application\Workflow\GetBatchReportHandler;
use App\Application\Workflow\PreviewDecisionsHandler;
use App\Application\Workflow\PreviewMovesHandler;
use App\Application\Workflow\PreviewPurgeHandler;
use App\Application\Workflow\PurgeAssetHandler;
use App\Application\Workflow\WorkflowEndpointsHandler;
use App\Api\Service\IdempotencyService;
use App\Asset\AssetRevisionTag;
use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Derived\DerivedFileRepositoryInterface;
use App\Controller\Api\WorkflowController;
use App\Entity\Asset;
use App\Entity\User;
use App\Infrastructure\Workflow\WorkflowGateway;
use App\Lock\Repository\OperationLockRepository;
use App\Workflow\BatchMoveReportRepositoryInterface;
use App\Workflow\Service\AssetPurgeStorageService;
use App\Workflow\Service\BatchWorkflowService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WorkflowControllerTest extends TestCase
{
    use TranslatorStubTrait;

    public function testPurgeBatchReturnsForbiddenForAgentActor(): void
    {
        $repo = new InMemoryAssetRepo([$this->asset('a2', AssetState::REJECTED)]);
        $connection = $this->createMock(Connection::class);
        $workflows = new BatchWorkflowService($repo, new AssetStateMachine(), $connection, $this->locks(false), $this->batchMoveReports(), $this->purgeStorage());
        $controller = $this->controller($workflows, $repo, $this->idempotencyPassthrough(), $this->translatorStub(), true, null, false);

        $request = Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"asset_uuids":["a2"],"confirm":true}');
        $request->headers->set('Idempotency-Key', 'idem-batch-agent');

        self::assertSame(Response::HTTP_FORBIDDEN, $controller->purgeBatch($request)->getStatusCode());
    }

    public function testPurgeBatchReturnsValidationFailedForInvalidPayload(): void
    {
        $repo = new InMemoryAssetRepo([$this->asset('a2', AssetState::REJECTED)]);
        $connection = $this->createMock(Connection::class);
        $workflows = new BatchWorkflowService($repo, new AssetStateMachine(), $connection, $this->locks(false), $this->batchMoveReports(), $this->purgeStorage());
        $controller = $this->controller($workflows, $repo, $this->idempotencyPassthrough(), $this->translatorStub(), false, null, false);

        $request = Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"asset_uuids":[],"confirm":false}');
        $request->headers->set('Idempotency-Key', 'idem-batch-invalid');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $controller->purgeBatch($request)->getStatusCode());
    }

    public function testPurgeBatchReturnsPerAssetResults(): void
    {
        $rejected = $this->asset('a2', AssetState::REJECTED);
        $ready = $this->asset('a3', AssetState::READY);
        $repo = new InMemoryAssetRepo([$rejected, $ready]);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $derivedFiles = $this->derivedFiles();
        $derivedFiles->method('listStoragePathsByAsset')->willReturn([]);
        $workflows = new BatchWorkflowService($repo, new AssetStateMachine(), $connection, $this->locks(false), $this->batchMoveReports(), new AssetPurgeStorageService($derivedFiles));
        $controller = $this->controller($workflows, $repo, $this->idempotencyPassthrough(), $this->translatorStub(), false, null, false);

        $request = Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"asset_uuids":["a2","a3"],"confirm":true}');
        $request->headers->set('Idempotency-Key', 'idem-batch-ok');

        $response = $controller->purgeBatch($request);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame(2, $payload['requested'] ?? null);
        self::assertSame(1, $payload['purged'] ?? null);
        self::assertSame(1, $payload['failed'] ?? null);
    }

    public function testPurgeEndpointsAndStateConflict(): void
    {
        $rejected = $this->asset('a2', AssetState::REJECTED);
        $ready = $this->asset('a3', AssetState::READY);
        $repo = new InMemoryAssetRepo([$rejected, $ready]);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $derivedFiles = $this->derivedFiles();
        $derivedFiles->method('listStoragePathsByAsset')->willReturn([]);
        $workflows = new BatchWorkflowService($repo, new AssetStateMachine(), $connection, $this->locks(false), $this->batchMoveReports(), new AssetPurgeStorageService($derivedFiles));
        $idempotency = $this->idempotencyPassthrough();
        $translator = $this->translatorStub();
        $controller = $this->controller($workflows, $repo, $idempotency, $translator, false, null, false);
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->previewPurge('missing')->getStatusCode());
        $purgeMissingRequest = Request::create('/x', 'POST');
        $purgeMissingRequest->headers->set('Idempotency-Key', 'idem-p1');
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->purge('missing', $purgeMissingRequest)->getStatusCode());
        self::assertSame(Response::HTTP_OK, $controller->previewPurge('a2')->getStatusCode());
        $purgeConflictRequest = Request::create('/x', 'POST');
        $purgeConflictRequest->headers->set('Idempotency-Key', 'idem-p2');
        $purgeConflictRequest->headers->set('If-Match', AssetRevisionTag::fromAsset($ready));
        self::assertSame(Response::HTTP_CONFLICT, $controller->purge('a3', $purgeConflictRequest)->getStatusCode());
        $purgeOkRequest = Request::create('/x', 'POST');
        $purgeOkRequest->headers->set('Idempotency-Key', 'idem-p3');
        $purgeOkRequest->headers->set('If-Match', AssetRevisionTag::fromAsset($rejected));
        self::assertSame(Response::HTTP_OK, $controller->purge('a2', $purgeOkRequest)->getStatusCode());
    }

    private function idempotencyPassthrough(): IdempotencyService
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);
        $connection->method('insert')->willReturn(1);

        return new IdempotencyService($connection, $this->translatorStub());
    }


    private function asset(string $uuid, AssetState $state): Asset
    {
        return new Asset(
            uuid: $uuid,
            mediaType: 'video',
            filename: 'file.mp4',
            state: $state,
            tags: [],
            notes: null,
            fields: [],
            createdAt: new \DateTimeImmutable('-1 hour'),
            updatedAt: new \DateTimeImmutable('-1 hour'),
        );
    }

    private function locks(bool $hasActive): OperationLockRepository
    {
        $locks = $this->createMock(OperationLockRepository::class);
        $locks->method('hasActiveLock')->willReturn($hasActive);
        $locks->method('acquire')->willReturn(true);
        $locks->method('release');

        return $locks;
    }

    private function derivedFiles(): DerivedFileRepositoryInterface
    {
        $derivedFiles = $this->createMock(DerivedFileRepositoryInterface::class);
        $derivedFiles->method('listStoragePathsByAsset')->willReturn([]);

        return $derivedFiles;
    }

    private function batchMoveReports(): BatchMoveReportRepositoryInterface
    {
        return $this->createMock(BatchMoveReportRepositoryInterface::class);
    }

    private function purgeStorage(): AssetPurgeStorageService
    {
        return new AssetPurgeStorageService($this->derivedFiles());
    }

    private function controller(
        BatchWorkflowService $workflows,
        AssetRepositoryInterface $assets,
        IdempotencyService $idempotency,
        TranslatorInterface $translator,
        bool $isAgentActor,
        ?User $authenticatedUser,
        bool $bulkDecisionsEnabled,
    ): WorkflowController {
        $agentActorGateway = new class ($isAgentActor) implements AgentActorGateway {
            public function __construct(
                private bool $isAgentActor,
            ) {
            }

            public function isAgent(): bool
            {
                return $this->isAgentActor;
            }
        };

        $authenticatedUserGateway = new class ($authenticatedUser) implements AuthenticatedUserGateway {
            public function __construct(
                private ?User $authenticatedUser,
            ) {
            }

            public function currentUser(): ?array
            {
                if (!$this->authenticatedUser instanceof User) {
                    return null;
                }

                return [
                    'id' => $this->authenticatedUser->getId(),
                    'email' => $this->authenticatedUser->getEmail(),
                    'roles' => $this->authenticatedUser->getRoles(),
                ];
            }
        };

        $workflowGateway = new WorkflowGateway($workflows, $assets);

        return new WorkflowController(
            $idempotency,
            new WorkflowEndpointsHandler(
                new ResolveAgentActorHandler($agentActorGateway),
                new ResolveAuthenticatedUserHandler($authenticatedUserGateway),
                new PreviewMovesHandler($workflowGateway),
                new ApplyMovesHandler($workflowGateway),
                new GetBatchReportHandler($workflowGateway),
                new CheckBulkDecisionsEnabledHandler($bulkDecisionsEnabled),
                new PreviewDecisionsHandler($workflowGateway),
                new ApplyDecisionsHandler($workflowGateway),
                new PreviewPurgeHandler($workflowGateway),
                new PurgeAssetHandler($workflowGateway),
            ),
            $translator,
            new AssetRequestPreconditionService($assets, $translator)
        );
    }
}

final class InMemoryAssetRepo implements AssetRepositoryInterface
{
    /** @var array<string, Asset> */
    private array $items = [];

    /**
     * @param array<int, Asset> $assets
     */
    public function __construct(array $assets)
    {
        foreach ($assets as $asset) {
            $this->items[$asset->getUuid()] = $asset;
        }
    }

    public function findByUuid(string $uuid): ?Asset
    {
        return $this->items[$uuid] ?? null;
    }

    public function listAssets(?string $state, ?string $mediaType, ?string $query, int $limit): array
    {
        return array_values(array_slice($this->items, 0, $limit));
    }

    public function save(Asset $asset): void
    {
        $this->items[$asset->getUuid()] = $asset;
    }
}
