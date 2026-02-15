<?php

namespace App\Application\Workflow;

use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Auth\ResolveAgentActorResult;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\ResolveAuthenticatedUserResult;

final class WorkflowEndpointsHandler
{
    public function __construct(
        private ResolveAgentActorHandler $resolveAgentActorHandler,
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
        private PreviewMovesHandler $previewMovesHandler,
        private ApplyMovesHandler $applyMovesHandler,
        private GetBatchReportHandler $getBatchReportHandler,
        private CheckBulkDecisionsEnabledHandler $checkBulkDecisionsEnabledHandler,
        private PreviewDecisionsHandler $previewDecisionsHandler,
        private ApplyDecisionsHandler $applyDecisionsHandler,
        private PreviewPurgeHandler $previewPurgeHandler,
        private PurgeAssetHandler $purgeAssetHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function previewMoves(array $payload): WorkflowEndpointResult
    {
        if ($this->isForbiddenAgentActor()) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        return new WorkflowEndpointResult(
            WorkflowEndpointResult::STATUS_SUCCESS,
            $this->previewMovesHandler->handle($this->uuidList($payload['uuids'] ?? null))
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function applyMoves(array $payload): WorkflowEndpointResult
    {
        if ($this->isForbiddenAgentActor()) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        return new WorkflowEndpointResult(
            WorkflowEndpointResult::STATUS_SUCCESS,
            $this->applyMovesHandler->handle($this->uuidList($payload['uuids'] ?? null))
        );
    }

    public function getBatch(string $batchId): WorkflowEndpointResult
    {
        if ($this->isForbiddenAgentActor()) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        $result = $this->getBatchReportHandler->handle($batchId);
        if ($result->status() === GetBatchReportResult::STATUS_NOT_FOUND) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_NOT_FOUND);
        }

        return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_SUCCESS, $result->report());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function previewDecisions(array $payload): WorkflowEndpointResult
    {
        if ($this->isForbiddenAgentActor()) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }
        if (!$this->checkBulkDecisionsEnabledHandler->handle()) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_FORBIDDEN_SCOPE);
        }

        $action = trim((string) ($payload['action'] ?? ''));
        $result = $this->previewDecisionsHandler->handle($action, $this->uuidList($payload['uuids'] ?? null));
        if ($result->status() === PreviewDecisionsResult::STATUS_VALIDATION_FAILED) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_VALIDATION_FAILED);
        }

        return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_SUCCESS, $result->payload());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function applyDecisions(array $payload): WorkflowEndpointResult
    {
        if ($this->isForbiddenAgentActor()) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }
        if (!$this->checkBulkDecisionsEnabledHandler->handle()) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_FORBIDDEN_SCOPE);
        }

        $action = trim((string) ($payload['action'] ?? ''));
        $result = $this->applyDecisionsHandler->handle($action, $this->uuidList($payload['uuids'] ?? null));
        if ($result->status() === ApplyDecisionsResult::STATUS_VALIDATION_FAILED) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_VALIDATION_FAILED);
        }

        return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_SUCCESS, $result->payload());
    }

    public function previewPurge(string $uuid): WorkflowEndpointResult
    {
        if ($this->isForbiddenAgentActor()) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        $result = $this->previewPurgeHandler->handle($uuid);
        if ($result->status() === PreviewPurgeResult::STATUS_NOT_FOUND) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_NOT_FOUND);
        }

        return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_SUCCESS, $result->payload());
    }

    public function purge(string $uuid): WorkflowEndpointResult
    {
        if ($this->isForbiddenAgentActor()) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        $result = $this->purgeAssetHandler->handle($uuid);
        if ($result->status() === PurgeAssetResult::STATUS_NOT_FOUND) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_NOT_FOUND);
        }
        if ($result->status() === PurgeAssetResult::STATUS_STATE_CONFLICT) {
            return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_STATE_CONFLICT);
        }

        return new WorkflowEndpointResult(WorkflowEndpointResult::STATUS_SUCCESS, $result->payload());
    }

    public function actorId(): string
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return 'anonymous';
        }

        return (string) $authenticatedUser->id();
    }

    public function isForbiddenAgentActor(): bool
    {
        return $this->resolveAgentActorHandler->handle()->status() === ResolveAgentActorResult::STATUS_AUTHORIZED;
    }

    public function isBulkDecisionsEnabled(): bool
    {
        return $this->checkBulkDecisionsEnabledHandler->handle();
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function uuidList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(static fn ($v): string => trim((string) $v), $value),
                static fn (string $v): bool => $v !== ''
            )
        );
    }
}
