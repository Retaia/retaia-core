<?php

namespace App\Tests\Unit\Controller;

use App\Api\Service\IdempotencyService;
use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Controller\Api\WorkflowController;
use App\Entity\Asset;
use App\Entity\User;
use App\Workflow\Service\BatchWorkflowService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WorkflowControllerTest extends TestCase
{
    public function testPreviewMovesForbiddenAndSuccess(): void
    {
        $repo = new InMemoryAssetRepo([$this->asset('a1', AssetState::DECIDED_KEEP)]);
        $connection = $this->createMock(Connection::class);
        $workflows = new BatchWorkflowService($repo, new AssetStateMachine(), $connection);
        $idempotency = $this->idempotencyPassthrough();
        $translator = $this->translator();

        $securityForbidden = $this->createMock(Security::class);
        $securityForbidden->method('isGranted')->with('ROLE_AGENT')->willReturn(true);
        $controller = new WorkflowController($workflows, $repo, $idempotency, $securityForbidden, $translator);
        self::assertSame(Response::HTTP_FORBIDDEN, $controller->previewMoves(Request::create('/x', 'POST'))->getStatusCode());

        $securityAllowed = $this->createMock(Security::class);
        $securityAllowed->method('isGranted')->with('ROLE_AGENT')->willReturn(false);
        $controller = new WorkflowController($workflows, $repo, $idempotency, $securityAllowed, $translator);
        self::assertSame(Response::HTTP_OK, $controller->previewMoves(Request::create('/x', 'POST'))->getStatusCode());
    }

    public function testDecisionAndBatchEndpoints(): void
    {
        $repo = new InMemoryAssetRepo([$this->asset('a1', AssetState::DECISION_PENDING)]);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);
        $workflows = new BatchWorkflowService($repo, new AssetStateMachine(), $connection);
        $idempotency = $this->idempotencyPassthrough();
        $translator = $this->translator();
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_AGENT')->willReturn(false);
        $security->method('getUser')->willReturn(new User('u1', 'u@example.test', 'hash', ['ROLE_USER'], true));

        $controller = new WorkflowController($workflows, $repo, $idempotency, $security, $translator);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $controller->previewDecisions(Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}'))->getStatusCode());
        self::assertSame(Response::HTTP_OK, $controller->previewDecisions(Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"action":"KEEP","uuids":["a1"]}'))->getStatusCode());
        $invalidApplyRequest = Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $invalidApplyRequest->headers->set('Idempotency-Key', 'idem-1');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $controller->applyDecisions($invalidApplyRequest)->getStatusCode());
        $validApplyRequest = Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"action":"KEEP","uuids":["a1"]}');
        $validApplyRequest->headers->set('Idempotency-Key', 'idem-2');
        self::assertSame(Response::HTTP_OK, $controller->applyDecisions($validApplyRequest)->getStatusCode());

        self::assertSame(Response::HTTP_NOT_FOUND, $controller->getBatch('missing')->getStatusCode());
    }

    public function testPurgeEndpointsAndStateConflict(): void
    {
        $rejected = $this->asset('a2', AssetState::REJECTED);
        $ready = $this->asset('a3', AssetState::READY);
        $repo = new InMemoryAssetRepo([$rejected, $ready]);
        $connection = $this->createMock(Connection::class);
        $workflows = new BatchWorkflowService($repo, new AssetStateMachine(), $connection);
        $idempotency = $this->idempotencyPassthrough();
        $translator = $this->translator();
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_AGENT')->willReturn(false);
        $security->method('getUser')->willReturn(null);

        $controller = new WorkflowController($workflows, $repo, $idempotency, $security, $translator);
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->previewPurge('missing')->getStatusCode());
        $purgeMissingRequest = Request::create('/x', 'POST');
        $purgeMissingRequest->headers->set('Idempotency-Key', 'idem-p1');
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->purge('missing', $purgeMissingRequest)->getStatusCode());
        self::assertSame(Response::HTTP_OK, $controller->previewPurge('a2')->getStatusCode());
        $purgeConflictRequest = Request::create('/x', 'POST');
        $purgeConflictRequest->headers->set('Idempotency-Key', 'idem-p2');
        self::assertSame(Response::HTTP_CONFLICT, $controller->purge('a3', $purgeConflictRequest)->getStatusCode());
        $purgeOkRequest = Request::create('/x', 'POST');
        $purgeOkRequest->headers->set('Idempotency-Key', 'idem-p3');
        self::assertSame(Response::HTTP_OK, $controller->purge('a2', $purgeOkRequest)->getStatusCode());
    }

    private function idempotencyPassthrough(): IdempotencyService
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);
        $connection->method('insert')->willReturn(1);

        return new IdempotencyService($connection);
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
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
