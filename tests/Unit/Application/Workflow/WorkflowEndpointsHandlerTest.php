<?php

namespace App\Tests\Unit\Application\Workflow;

use App\Application\Auth\Port\AgentActorGateway;
use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Workflow\ApplyDecisionsHandler;
use App\Application\Workflow\ApplyMovesHandler;
use App\Application\Workflow\CheckBulkDecisionsEnabledHandler;
use App\Application\Workflow\GetBatchReportHandler;
use App\Application\Workflow\Port\WorkflowGateway;
use App\Application\Workflow\PreviewDecisionsHandler;
use App\Application\Workflow\PreviewMovesHandler;
use App\Application\Workflow\PreviewPurgeHandler;
use App\Application\Workflow\PurgeAssetHandler;
use App\Application\Workflow\WorkflowEndpointResult;
use App\Application\Workflow\WorkflowEndpointsHandler;
use PHPUnit\Framework\TestCase;

final class WorkflowEndpointsHandlerTest extends TestCase
{
    public function testPreviewMovesReturnsForbiddenActorWhenAgentNotAllowed(): void
    {
        $handler = $this->buildHandler(true, null);

        $result = $handler->previewMoves(['uuids' => ['a1']]);

        self::assertSame(WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR, $result->status());
    }

    public function testGetBatchReturnsNotFoundWhenMissing(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::once())->method('getBatchReport')->with('batch_1')->willReturn(null);

        $handler = $this->buildHandler(false, null, true, $gateway);

        $result = $handler->getBatch('batch_1');

        self::assertSame(WorkflowEndpointResult::STATUS_NOT_FOUND, $result->status());
    }

    public function testPreviewDecisionsReturnsForbiddenScopeWhenBulkDisabled(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::never())->method('previewDecisions');

        $handler = $this->buildHandler(false, null, false, $gateway);
        $result = $handler->previewDecisions(['action' => 'KEEP', 'uuids' => ['a1']]);

        self::assertSame(WorkflowEndpointResult::STATUS_FORBIDDEN_SCOPE, $result->status());
    }

    public function testApplyDecisionsReturnsValidationFailedWhenPayloadInvalid(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::never())->method('applyDecisions');

        $handler = $this->buildHandler(false, null, true, $gateway);
        $result = $handler->applyDecisions(['action' => '', 'uuids' => ['a1']]);

        self::assertSame(WorkflowEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

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
        $gateway->method('previewMoves')->willReturn(['items' => []]);
        $gateway->method('applyMoves')->willReturn(['batch_id' => 'b1']);
        $gateway->method('previewPurge')->willReturn(['eligible' => true]);
        $gateway->method('getBatchReport')->willReturn(['batch_id' => 'b1']);
        $gateway->method('previewDecisions')->willReturn(['eligible_count' => 1]);
        $gateway->method('applyDecisions')->willReturn(['batch_id' => 'd1']);
        $gateway->method('purge')->willReturn(['status' => 'PURGED', 'asset' => ['uuid' => 'a1', 'state' => 'PURGED']]);

        $agentActorGateway = $this->createMock(AgentActorGateway::class);
        $agentActorGateway->method('isAgent')->willReturn($isAgentActor);

        $authenticatedUserGateway = $this->createMock(AuthenticatedUserGateway::class);
        $authenticatedUserGateway->method('currentUser')->willReturn($currentUser);

        return new WorkflowEndpointsHandler(
            new ResolveAgentActorHandler($agentActorGateway),
            new ResolveAuthenticatedUserHandler($authenticatedUserGateway),
            new PreviewMovesHandler($gateway),
            new ApplyMovesHandler($gateway),
            new GetBatchReportHandler($gateway),
            new CheckBulkDecisionsEnabledHandler($bulkDecisionsEnabled),
            new PreviewDecisionsHandler($gateway),
            new ApplyDecisionsHandler($gateway),
            new PreviewPurgeHandler($gateway),
            new PurgeAssetHandler($gateway),
        );
    }
}
