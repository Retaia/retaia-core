<?php

namespace App\Tests\Unit\Controller;

use App\Api\Service\AssetRequestPreconditionService;
use App\Application\Asset\AssetEndpointsHandler;
use App\Application\Auth\Port\AgentActorGateway;
use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Controller\Api\AssetHttpResponder;
use App\Controller\Api\AssetMutationController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AssetMutationControllerTest extends TestCase
{
    public function testPatchReturnsForbiddenForAgentActor(): void
    {
        $controller = new AssetMutationController(
            $this->forbiddenAssetEndpointsHandler(),
            new AssetRequestPreconditionService($this->createStub(AssetRepositoryInterface::class)),
            $this->responder(),
        );

        $response = $controller->patch('asset-1', Request::create('/api/v1/assets/asset-1', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: '{}'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('FORBIDDEN_ACTOR', json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['code']);
    }

    private function forbiddenAssetEndpointsHandler(): AssetEndpointsHandler
    {
        $readGateway = $this->createStub(\App\Application\Asset\Port\AssetReadGateway::class);
        $readGateway->method('list')->willReturn(['items' => [], 'has_more' => false]);
        $readGateway->method('getByUuid')->willReturn(null);

        $patchGateway = $this->createStub(\App\Application\Asset\Port\AssetPatchGateway::class);
        $patchGateway->method('patch')->willReturn(['status' => 'PATCHED', 'payload' => []]);

        $workflowGateway = $this->createStub(\App\Application\Asset\Port\AssetWorkflowGateway::class);
        $workflowGateway->method('decide')->willReturn(['status' => 'DECIDED', 'payload' => []]);
        $workflowGateway->method('reopen')->willReturn(['status' => 'REOPENED', 'payload' => []]);
        $workflowGateway->method('reprocess')->willReturn(['status' => 'REPROCESSED', 'payload' => []]);

        return new AssetEndpointsHandler(
            new \App\Application\Asset\ListAssetsHandler($readGateway),
            new \App\Application\Asset\GetAssetHandler($readGateway),
            new \App\Application\Asset\PatchAssetHandler($patchGateway),
            new \App\Application\Asset\DecideAssetHandler($workflowGateway),
            new \App\Application\Asset\ReopenAssetHandler($workflowGateway),
            new \App\Application\Asset\ReprocessAssetHandler($workflowGateway),
            new ResolveAgentActorHandler(new class implements AgentActorGateway {
                public function isAgent(): bool
                {
                    return true;
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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new AssetHttpResponder(
            $translator,
            new AssetRequestPreconditionService($this->createStub(AssetRepositoryInterface::class))
        );
    }
}
