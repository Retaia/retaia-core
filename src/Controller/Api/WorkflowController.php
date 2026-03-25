<?php

namespace App\Controller\Api;

use App\Api\Service\AssetRequestPreconditionService;
use App\Application\Workflow\WorkflowEndpointsHandler;
use App\Application\Workflow\WorkflowEndpointResult;
use App\Api\Service\IdempotencyService;
use App\Controller\RequestPayloadTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WorkflowController
{
    use ApiErrorResponderTrait;
    use RequestPayloadTrait;

    public function __construct(
        private IdempotencyService $idempotency,
        private WorkflowEndpointsHandler $workflowEndpointsHandler,
        private TranslatorInterface $translator,
        private AssetRequestPreconditionService $assetPreconditions,
    ) {
    }

    #[Route('/api/v1/batches/moves/preview', name: 'api_batches_moves_preview', methods: ['POST'])]
    public function previewMoves(Request $request): JsonResponse
    {
        $result = $this->workflowEndpointsHandler->previewMoves($this->payload($request));
        if ($result->status() === WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->forbiddenActor();
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
    }

    #[Route('/api/v1/batches/moves', name: 'api_batches_moves_apply', methods: ['POST'])]
    public function applyMoves(Request $request): JsonResponse
    {
        if ($this->workflowEndpointsHandler->isForbiddenAgentActor()) {
            return $this->forbiddenActor();
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($request): JsonResponse {
            $result = $this->workflowEndpointsHandler->applyMoves($this->payload($request));
            if ($result->status() === WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR) {
                return $this->forbiddenActor();
            }

            return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
        });
    }

    #[Route('/api/v1/batches/moves/{batchId}', name: 'api_batches_moves_get', methods: ['GET'])]
    public function getBatch(string $batchId): JsonResponse
    {
        $result = $this->workflowEndpointsHandler->getBatch($batchId);
        if ($result->status() === WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->forbiddenActor();
        }
        if ($result->status() === WorkflowEndpointResult::STATUS_NOT_FOUND) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
    }

    #[Route('/api/v1/decisions/preview', name: 'api_decisions_preview', methods: ['POST'])]
    public function previewDecisions(Request $request): JsonResponse
    {
        $result = $this->workflowEndpointsHandler->previewDecisions($this->payload($request));
        if ($result->status() === WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->forbiddenActor();
        }
        if ($result->status() === WorkflowEndpointResult::STATUS_FORBIDDEN_SCOPE) {
            return $this->forbiddenScope();
        }
        if ($result->status() === WorkflowEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'action and uuids are required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
    }

    #[Route('/api/v1/decisions/apply', name: 'api_decisions_apply', methods: ['POST'])]
    public function applyDecisions(Request $request): JsonResponse
    {
        if ($this->workflowEndpointsHandler->isForbiddenAgentActor()) {
            return $this->forbiddenActor();
        }
        if (!$this->workflowEndpointsHandler->isBulkDecisionsEnabled()) {
            return $this->forbiddenScope();
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($request): JsonResponse {
            $result = $this->workflowEndpointsHandler->applyDecisions($this->payload($request));
            if ($result->status() === WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR) {
                return $this->forbiddenActor();
            }
            if ($result->status() === WorkflowEndpointResult::STATUS_FORBIDDEN_SCOPE) {
                return $this->forbiddenScope();
            }
            if ($result->status() === WorkflowEndpointResult::STATUS_VALIDATION_FAILED) {
                return new JsonResponse([
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'action and uuids are required',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
        });
    }

    #[Route('/api/v1/assets/purge', name: 'api_assets_purge_batch', methods: ['POST'])]
    public function purgeBatch(Request $request): JsonResponse
    {
        if ($this->workflowEndpointsHandler->isForbiddenAgentActor()) {
            return $this->forbiddenActor();
        }

        $payload = $this->payload($request);
        $assetUuids = array_values(array_filter(
            array_map(static fn ($value): string => trim((string) $value), (array) ($payload['asset_uuids'] ?? [])),
            static fn (string $uuid): bool => $uuid !== ''
        ));
        $confirm = (bool) ($payload['confirm'] ?? false);

        if ($assetUuids === [] || $confirm !== true) {
            return $this->errorResponse('VALIDATION_FAILED', 'asset_uuids and confirm=true are required', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($assetUuids): JsonResponse {
            $results = [];
            $purged = 0;
            $failed = 0;

            foreach ($assetUuids as $uuid) {
                $result = $this->workflowEndpointsHandler->purge($uuid);
                $status = match ($result->status()) {
                    WorkflowEndpointResult::STATUS_SUCCESS => 'PURGED',
                    WorkflowEndpointResult::STATUS_NOT_FOUND => 'NOT_FOUND',
                    WorkflowEndpointResult::STATUS_STATE_CONFLICT => 'STATE_CONFLICT',
                    default => 'FORBIDDEN_ACTOR',
                };

                if ($status === 'FORBIDDEN_ACTOR') {
                    return $this->forbiddenActor();
                }

                if ($status === 'PURGED') {
                    ++$purged;
                } else {
                    ++$failed;
                }

                $results[] = [
                    'asset_uuid' => $uuid,
                    'status' => $status,
                ];
            }

            return new JsonResponse([
                'requested' => count($assetUuids),
                'purged' => $purged,
                'failed' => $failed,
                'results' => $results,
            ], Response::HTTP_OK);
        });
    }

    #[Route('/api/v1/assets/{uuid}/purge/preview', name: 'api_assets_purge_preview', methods: ['POST'])]
    public function previewPurge(string $uuid): JsonResponse
    {
        $result = $this->workflowEndpointsHandler->previewPurge($uuid);
        if ($result->status() === WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->forbiddenActor();
        }
        if ($result->status() === WorkflowEndpointResult::STATUS_NOT_FOUND) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
    }

    #[Route('/api/v1/assets/{uuid}/purge', name: 'api_assets_purge_apply', methods: ['POST'])]
    public function purge(string $uuid, Request $request): JsonResponse
    {
        if ($this->workflowEndpointsHandler->isForbiddenAgentActor()) {
            return $this->forbiddenActor();
        }
        $preconditionViolation = $this->assetPreconditions->violationResponse($request, $uuid);
        if ($preconditionViolation instanceof JsonResponse) {
            return $preconditionViolation;
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($uuid): JsonResponse {
            $result = $this->workflowEndpointsHandler->purge($uuid);
            if ($result->status() === WorkflowEndpointResult::STATUS_FORBIDDEN_ACTOR) {
                return $this->forbiddenActor();
            }
            if ($result->status() === WorkflowEndpointResult::STATUS_NOT_FOUND) {
                return new JsonResponse([
                    'code' => 'NOT_FOUND',
                    'message' => $this->translator->trans('asset.error.not_found'),
                ], Response::HTTP_NOT_FOUND);
            }

            if ($result->status() === WorkflowEndpointResult::STATUS_STATE_CONFLICT) {
                return new JsonResponse([
                    'code' => 'STATE_CONFLICT',
                    'message' => $this->translator->trans('asset.error.state_conflict'),
                ], Response::HTTP_CONFLICT);
            }

            return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
        });
    }

    private function actorId(): string
    {
        return $this->workflowEndpointsHandler->actorId();
    }

    private function forbiddenActor(): JsonResponse
    {
        return $this->errorResponse('FORBIDDEN_ACTOR', $this->translator->trans('auth.error.forbidden_actor'), Response::HTTP_FORBIDDEN);
    }

    private function forbiddenScope(): JsonResponse
    {
        return $this->errorResponse('FORBIDDEN_SCOPE', $this->translator->trans('auth.error.forbidden_scope'), Response::HTTP_FORBIDDEN);
    }
}
