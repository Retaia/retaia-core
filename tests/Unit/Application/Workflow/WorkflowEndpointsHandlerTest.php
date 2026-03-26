<?php

namespace App\Tests\Unit\Application\Workflow;

use App\Application\Auth\Port\AgentActorGateway;
use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Workflow\CheckBulkDecisionsEnabledHandler;
use App\Application\Workflow\Port\WorkflowGateway;
use App\Application\Workflow\PreviewPurgeHandler;
use App\Application\Workflow\PurgeAssetHandler;
use App\Application\Workflow\WorkflowEndpointResult;
use App\Application\Workflow\WorkflowEndpointsHandler;
use PHPUnit\Framework\TestCase;

final class WorkflowEndpointsHandlerTest extends TestCase
{
    public function testPurgeReturnsStateConflict(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::once())
            ->method('purge')
            ->with('asset_1')
            ->willReturn(['status' => 'STATE_CONFLICT', 'asset' => null]);

        $handler = $this->buildHandler(false, null, true, $gateway);
        $result = $handler->purge('asset_1');

        self::assertSame(WorkflowEndpointResult::STATUS_STATE_CONFLICT, $result->status());
    }

    public function testActorIdReturnsAnonymousWhenUnauthorized(): void
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
        bool $bulkDecisionsEnabled = true,
        ?WorkflowGateway $gateway = null,
    ): WorkflowEndpointsHandler {
        $gateway ??= $this->createMock(WorkflowGateway::class);
        $gateway->method('previewPurge')->willReturn(['eligible' => true]);
        $gateway->method('purge')->willReturn(['status' => 'PURGED', 'asset' => ['uuid' => 'a1', 'state' => 'PURGED']]);

        $agentActorGateway = $this->createMock(AgentActorGateway::class);
        $agentActorGateway->method('isAgent')->willReturn($isAgentActor);

        $authenticatedUserGateway = $this->createMock(AuthenticatedUserGateway::class);
        $authenticatedUserGateway->method('currentUser')->willReturn($currentUser);

        return new WorkflowEndpointsHandler(
            new ResolveAgentActorHandler($agentActorGateway),
            new ResolveAuthenticatedUserHandler($authenticatedUserGateway),
            new \App\Application\Workflow\PreviewMovesHandler($gateway),
            new \App\Application\Workflow\ApplyMovesHandler($gateway),
            new \App\Application\Workflow\GetBatchReportHandler($gateway),
            new CheckBulkDecisionsEnabledHandler($bulkDecisionsEnabled),
            new \App\Application\Workflow\PreviewDecisionsHandler($gateway),
            new \App\Application\Workflow\ApplyDecisionsHandler($gateway),
            new PreviewPurgeHandler($gateway),
            new PurgeAssetHandler($gateway),
        );
    }
}
