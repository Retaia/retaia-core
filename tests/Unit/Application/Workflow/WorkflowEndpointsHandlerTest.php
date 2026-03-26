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

    public function testPreviewMovesRejectsAgentActor(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::never())->method('previewMoves');

        $result = $this->buildHandler(true, null, true, $gateway)->previewMoves(['uuids' => ['a1']]);

        self::assertSame(WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR, $result->status());
    }

    public function testPreviewMovesNormalizesUuidList(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::once())
            ->method('previewMoves')
            ->with(['a1', 'a2'])
            ->willReturn(['batch_id' => 'batch-1']);

        $result = $this->buildHandler(false, null, true, $gateway)
            ->previewMoves(['uuids' => [' a1 ', '', 'a2', '   ']]);

        self::assertSame(WorkflowEndpointResult::STATUS_SUCCESS, $result->status());
        self::assertSame(['batch_id' => 'batch-1'], $result->payload());
    }

    public function testApplyMovesReturnsSuccessPayload(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::once())
            ->method('applyMoves')
            ->with(['a1'])
            ->willReturn(['status' => 'accepted']);

        $result = $this->buildHandler(false, null, true, $gateway)->applyMoves(['uuids' => ['a1']]);

        self::assertSame(WorkflowEndpointResult::STATUS_SUCCESS, $result->status());
        self::assertSame(['status' => 'accepted'], $result->payload());
    }

    public function testGetBatchReturnsNotFound(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::once())
            ->method('getBatchReport')
            ->with('batch-missing')
            ->willReturn(null);

        $result = $this->buildHandler(false, null, true, $gateway)->getBatch('batch-missing');

        self::assertSame(WorkflowEndpointResult::STATUS_NOT_FOUND, $result->status());
    }

    public function testGetBatchReturnsReport(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::once())
            ->method('getBatchReport')
            ->with('batch-1')
            ->willReturn(['batch_id' => 'batch-1', 'items' => []]);

        $result = $this->buildHandler(false, null, true, $gateway)->getBatch('batch-1');

        self::assertSame(WorkflowEndpointResult::STATUS_SUCCESS, $result->status());
        self::assertSame(['batch_id' => 'batch-1', 'items' => []], $result->payload());
    }

    public function testPreviewDecisionsRejectsWhenBulkDecisionsDisabled(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::never())->method('previewDecisions');

        $result = $this->buildHandler(false, null, false, $gateway)
            ->previewDecisions(['action' => 'KEEP', 'uuids' => ['a1']]);

        self::assertSame(WorkflowEndpointResult::STATUS_FORBIDDEN_SCOPE, $result->status());
    }

    public function testPreviewDecisionsReturnsValidationFailed(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::never())->method('previewDecisions');

        $result = $this->buildHandler(false, null, true, $gateway)
            ->previewDecisions(['action' => '   ', 'uuids' => ['a1']]);

        self::assertSame(WorkflowEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testApplyDecisionsReturnsValidationFailed(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::never())->method('applyDecisions');

        $result = $this->buildHandler(false, null, true, $gateway)
            ->applyDecisions(['action' => ' REJECT ', 'uuids' => []]);

        self::assertSame(WorkflowEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testPreviewPurgeReturnsNotFound(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::once())
            ->method('previewPurge')
            ->with('missing')
            ->willReturn(null);

        $result = $this->buildHandler(false, null, true, $gateway)->previewPurge('missing');

        self::assertSame(WorkflowEndpointResult::STATUS_NOT_FOUND, $result->status());
    }

    public function testActorIdReturnsAuthenticatedUserId(): void
    {
        $handler = $this->buildHandler(false, [
            'id' => 'user-1',
            'email' => 'user@example.test',
            'roles' => ['ROLE_USER'],
        ]);

        self::assertSame('user-1', $handler->actorId());
    }

    public function testActorIdReturnsAnonymousWhenUnauthorized(): void
    {
        $handler = $this->buildHandler(false, null);

        self::assertSame('anonymous', $handler->actorId());
    }

    public function testIsBulkDecisionsEnabledReturnsFeatureFlag(): void
    {
        self::assertTrue($this->buildHandler(false, null, true)->isBulkDecisionsEnabled());
        self::assertFalse($this->buildHandler(false, null, false)->isBulkDecisionsEnabled());
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
        $gateway->method('previewMoves')->willReturn(['batch_id' => 'preview-default']);
        $gateway->method('applyMoves')->willReturn(['status' => 'applied-default']);
        $gateway->method('getBatchReport')->willReturn(['batch_id' => 'default']);
        $gateway->method('previewDecisions')->willReturn(['status' => 'PREVIEWED', 'items' => []]);
        $gateway->method('applyDecisions')->willReturn(['status' => 'APPLIED', 'items' => []]);

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
