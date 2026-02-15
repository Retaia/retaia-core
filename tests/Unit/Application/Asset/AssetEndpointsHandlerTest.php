<?php

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\AssetEndpointResult;
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
use PHPUnit\Framework\TestCase;

final class AssetEndpointsHandlerTest extends TestCase
{
    public function testListReturnsValidationFailedWhenModeInvalid(): void
    {
        $readGateway = $this->createMock(AssetReadGateway::class);
        $readGateway->expects(self::never())->method('list');

        $handler = $this->buildHandler(false, null, $readGateway);
        $result = $handler->list(null, null, null, 50, [], 'INVALID');

        self::assertSame(AssetEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testListReturnsForbiddenScopeWhenGatewayReturnsNull(): void
    {
        $readGateway = $this->createMock(AssetReadGateway::class);
        $readGateway->expects(self::once())->method('list')->willReturn(null);

        $handler = $this->buildHandler(false, null, $readGateway);
        $result = $handler->list(null, null, null, 50, ['wedding'], 'AND');

        self::assertSame(AssetEndpointResult::STATUS_FORBIDDEN_SCOPE, $result->status());
    }

    public function testPatchReturnsForbiddenActorWhenAgentActor(): void
    {
        $patchGateway = $this->createMock(AssetPatchGateway::class);
        $patchGateway->expects(self::never())->method('patch');

        $handler = $this->buildHandler(true, null, null, $patchGateway);
        $result = $handler->patch('a1', ['notes' => 'x']);

        self::assertSame(AssetEndpointResult::STATUS_FORBIDDEN_ACTOR, $result->status());
    }

    public function testDecisionReturnsValidationFailed(): void
    {
        $workflowGateway = $this->createMock(AssetWorkflowGateway::class);
        $workflowGateway->expects(self::once())
            ->method('decide')
            ->with('a1', '')
            ->willReturn(['status' => 'VALIDATION_FAILED_ACTION_REQUIRED', 'payload' => null]);

        $handler = $this->buildHandler(false, null, null, null, $workflowGateway);
        $result = $handler->decision('a1', ['action' => '']);

        self::assertSame(AssetEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testReprocessReturnsStateConflict(): void
    {
        $workflowGateway = $this->createMock(AssetWorkflowGateway::class);
        $workflowGateway->expects(self::once())
            ->method('reprocess')
            ->with('a2')
            ->willReturn(['status' => 'STATE_CONFLICT', 'payload' => null]);

        $handler = $this->buildHandler(false, null, null, null, $workflowGateway);
        $result = $handler->reprocess('a2');

        self::assertSame(AssetEndpointResult::STATUS_STATE_CONFLICT, $result->status());
    }

    public function testActorIdReturnsAnonymousWhenUnauthenticated(): void
    {
        $handler = $this->buildHandler(false, null);

        self::assertSame('anonymous', $handler->actorId());
    }

    /**
     * @param array{id: string, email: string, roles: array<int, string>}|null $currentUser
     */
    private function buildHandler(
        bool $isAgentActor,
        ?array $currentUser,
        ?AssetReadGateway $readGateway = null,
        ?AssetPatchGateway $patchGateway = null,
        ?AssetWorkflowGateway $workflowGateway = null,
    ): AssetEndpointsHandler {
        $readGateway ??= $this->createMock(AssetReadGateway::class);
        $readGateway->method('getByUuid')->willReturn(['uuid' => 'a1']);
        $readGateway->method('list')->willReturn([['uuid' => 'a1']]);

        $patchGateway ??= $this->createMock(AssetPatchGateway::class);
        $patchGateway->method('patch')->willReturn(['status' => 'UPDATED', 'payload' => ['uuid' => 'a1']]);

        $workflowGateway ??= $this->createMock(AssetWorkflowGateway::class);
        $workflowGateway->method('decide')->willReturn(['status' => 'DECIDED', 'payload' => ['uuid' => 'a1']]);
        $workflowGateway->method('reopen')->willReturn(['status' => 'REOPENED', 'payload' => ['uuid' => 'a1']]);
        $workflowGateway->method('reprocess')->willReturn(['status' => 'REPROCESSED', 'payload' => ['uuid' => 'a1']]);

        $agentActorGateway = $this->createMock(AgentActorGateway::class);
        $agentActorGateway->method('isAgent')->willReturn($isAgentActor);

        $authenticatedUserGateway = $this->createMock(AuthenticatedUserGateway::class);
        $authenticatedUserGateway->method('currentUser')->willReturn($currentUser);

        return new AssetEndpointsHandler(
            new ListAssetsHandler($readGateway),
            new GetAssetHandler($readGateway),
            new PatchAssetHandler($patchGateway),
            new DecideAssetHandler($workflowGateway),
            new ReopenAssetHandler($workflowGateway),
            new ReprocessAssetHandler($workflowGateway),
            new ResolveAgentActorHandler($agentActorGateway),
            new ResolveAuthenticatedUserHandler($authenticatedUserGateway),
        );
    }
}
