<?php

namespace App\Tests\Unit\Controller;

use App\Api\Service\AssetRequestPreconditionService;
use App\Api\Service\IdempotencyService;
use App\Application\Asset\AssetEndpointsHandler;
use App\Application\Asset\DecideAssetHandler;
use App\Application\Asset\GetAssetHandler;
use App\Application\Asset\ListAssetsHandler;
use App\Application\Asset\PatchAssetHandler;
use App\Application\Asset\Port\AssetPatchGateway;
use App\Application\Asset\Port\AssetReadGateway;
use App\Application\Asset\Port\AssetWorkflowGateway;
use App\Application\Asset\ReopenAssetHandler;
use App\Application\Asset\ReprocessAssetHandler;
use App\Application\Auth\Port\AgentActorGateway;
use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Controller\Api\AssetHttpResponder;
use App\Controller\Api\AssetWorkflowController;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AssetWorkflowControllerTest extends TestCase
{
    public function testReprocessRequiresIdempotencyKey(): void
    {
        $controller = new AssetWorkflowController(
            $this->assetEndpointsHandler(),
            new AssetRequestPreconditionService($this->createStub(AssetRepositoryInterface::class), $this->translator()),
            $this->responder(),
            new IdempotencyService(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $this->translator())
        );

        $response = $controller->reprocess('asset-1', Request::create('/api/v1/assets/asset-1/reprocess', 'POST'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('MISSING_IDEMPOTENCY_KEY', json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['code']);
    }

    private function assetEndpointsHandler(): AssetEndpointsHandler
    {
        $readGateway = $this->createStub(AssetReadGateway::class);
        $readGateway->method('list')->willReturn(['items' => [], 'has_more' => false]);
        $readGateway->method('getByUuid')->willReturn(null);

        $patchGateway = $this->createStub(AssetPatchGateway::class);
        $patchGateway->method('patch')->willReturn(['status' => 'PATCHED', 'payload' => []]);

        $workflowGateway = $this->createStub(AssetWorkflowGateway::class);
        $workflowGateway->method('decide')->willReturn(['status' => 'DECIDED', 'payload' => []]);
        $workflowGateway->method('reopen')->willReturn(['status' => 'REOPENED', 'payload' => ['uuid' => 'asset-1', 'updated_at' => '2026-04-02T12:00:00+00:00']]);
        $workflowGateway->method('reprocess')->willReturn(['status' => 'REPROCESSED', 'payload' => ['uuid' => 'asset-1', 'updated_at' => '2026-04-02T12:00:00+00:00']]);

        return new AssetEndpointsHandler(
            new ListAssetsHandler($readGateway),
            new GetAssetHandler($readGateway),
            new PatchAssetHandler($patchGateway),
            new DecideAssetHandler($workflowGateway),
            new ReopenAssetHandler($workflowGateway),
            new ReprocessAssetHandler($workflowGateway),
            new ResolveAgentActorHandler(new class implements AgentActorGateway {
                public function isAgent(): bool
                {
                    return false;
                }
            }),
            new ResolveAuthenticatedUserHandler(new class implements AuthenticatedUserGateway {
                public function currentUser(): ?array
                {
                    return ['id' => 'user-1', 'email' => 'user@example.com', 'roles' => ['ROLE_USER']];
                }
            })
        );
    }

    private function responder(): AssetHttpResponder
    {
        return new AssetHttpResponder(
            $this->translator(),
            new AssetRequestPreconditionService($this->createStub(AssetRepositoryInterface::class), $this->translator())
        );
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
