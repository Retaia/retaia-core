<?php

namespace App\Controller\Api;

use App\Application\Workflow\WorkflowEndpointsHandler;
use App\Application\Workflow\WorkflowEndpointResult;
use App\Api\Service\IdempotencyService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WorkflowController
{
    public function __construct(
        private IdempotencyService $idempotency,
        private WorkflowEndpointsHandler $workflowEndpointsHandler,
        private TranslatorInterface $translator,
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

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        if ($request->getContent() === '') {
            return [];
        }

        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function actorId(): string
    {
        return $this->workflowEndpointsHandler->actorId();
    }

    private function forbiddenActor(): JsonResponse
    {
        return new JsonResponse([
            'code' => 'FORBIDDEN_ACTOR',
            'message' => $this->translator->trans('auth.error.forbidden_actor'),
        ], Response::HTTP_FORBIDDEN);
    }

    private function forbiddenScope(): JsonResponse
    {
        return new JsonResponse([
            'code' => 'FORBIDDEN_SCOPE',
            'message' => $this->translator->trans('auth.error.forbidden_scope'),
        ], Response::HTTP_FORBIDDEN);
    }
}
