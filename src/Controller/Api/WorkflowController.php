<?php

namespace App\Controller\Api;

use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Auth\ResolveAgentActorResult;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\ResolveAuthenticatedUserResult;
use App\Application\Workflow\ApplyDecisionsHandler;
use App\Application\Workflow\ApplyDecisionsResult;
use App\Application\Workflow\ApplyMovesHandler;
use App\Application\Workflow\CheckBulkDecisionsEnabledHandler;
use App\Application\Workflow\GetBatchReportHandler;
use App\Application\Workflow\GetBatchReportResult;
use App\Application\Workflow\PreviewDecisionsHandler;
use App\Application\Workflow\PreviewDecisionsResult;
use App\Application\Workflow\PreviewMovesHandler;
use App\Application\Workflow\PreviewPurgeHandler;
use App\Application\Workflow\PreviewPurgeResult;
use App\Application\Workflow\PurgeAssetHandler;
use App\Application\Workflow\PurgeAssetResult;
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
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/v1/batches/moves/preview', name: 'api_batches_moves_preview', methods: ['POST'])]
    public function previewMoves(Request $request): JsonResponse
    {
        if ($this->isForbiddenAgentActor()) {
            return $this->forbiddenActor();
        }

        $uuids = $this->uuidListFromPayload($request);

        return new JsonResponse($this->previewMovesHandler->handle($uuids), Response::HTTP_OK);
    }

    #[Route('/api/v1/batches/moves', name: 'api_batches_moves_apply', methods: ['POST'])]
    public function applyMoves(Request $request): JsonResponse
    {
        if ($this->isForbiddenAgentActor()) {
            return $this->forbiddenActor();
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($request): JsonResponse {
            $uuids = $this->uuidListFromPayload($request);

            return new JsonResponse($this->applyMovesHandler->handle($uuids), Response::HTTP_OK);
        });
    }

    #[Route('/api/v1/batches/moves/{batchId}', name: 'api_batches_moves_get', methods: ['GET'])]
    public function getBatch(string $batchId): JsonResponse
    {
        if ($this->isForbiddenAgentActor()) {
            return $this->forbiddenActor();
        }

        $result = $this->getBatchReportHandler->handle($batchId);
        if ($result->status() === GetBatchReportResult::STATUS_NOT_FOUND) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($result->report() ?? [], Response::HTTP_OK);
    }

    #[Route('/api/v1/decisions/preview', name: 'api_decisions_preview', methods: ['POST'])]
    public function previewDecisions(Request $request): JsonResponse
    {
        if ($this->isForbiddenAgentActor()) {
            return $this->forbiddenActor();
        }
        if (!$this->checkBulkDecisionsEnabledHandler->handle()) {
            return $this->forbiddenScope();
        }

        $payload = $this->payload($request);
        $action = trim((string) ($payload['action'] ?? ''));
        $uuids = $this->uuidList($payload['uuids'] ?? null);
        $result = $this->previewDecisionsHandler->handle($action, $uuids);
        if ($result->status() === PreviewDecisionsResult::STATUS_VALIDATION_FAILED) {
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
        if ($this->isForbiddenAgentActor()) {
            return $this->forbiddenActor();
        }
        if (!$this->checkBulkDecisionsEnabledHandler->handle()) {
            return $this->forbiddenScope();
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($request): JsonResponse {
            $payload = $this->payload($request);
            $action = trim((string) ($payload['action'] ?? ''));
            $uuids = $this->uuidList($payload['uuids'] ?? null);
            $result = $this->applyDecisionsHandler->handle($action, $uuids);
            if ($result->status() === ApplyDecisionsResult::STATUS_VALIDATION_FAILED) {
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
        if ($this->isForbiddenAgentActor()) {
            return $this->forbiddenActor();
        }

        $result = $this->previewPurgeHandler->handle($uuid);
        if ($result->status() === PreviewPurgeResult::STATUS_NOT_FOUND) {
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
        if ($this->isForbiddenAgentActor()) {
            return $this->forbiddenActor();
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($uuid): JsonResponse {
            $result = $this->purgeAssetHandler->handle($uuid);
            if ($result->status() === PurgeAssetResult::STATUS_NOT_FOUND) {
                return new JsonResponse([
                    'code' => 'NOT_FOUND',
                    'message' => $this->translator->trans('asset.error.not_found'),
                ], Response::HTTP_NOT_FOUND);
            }

            if ($result->status() === PurgeAssetResult::STATUS_STATE_CONFLICT) {
                return new JsonResponse([
                    'code' => 'STATE_CONFLICT',
                    'message' => $this->translator->trans('asset.error.state_conflict'),
                ], Response::HTTP_CONFLICT);
            }

            return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
        });
    }

    /**
     * @return array<int, string>|null
     */
    private function uuidListFromPayload(Request $request): ?array
    {
        $payload = $this->payload($request);

        return $this->uuidList($payload['uuids'] ?? null);
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

        return array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $value), static fn (string $v): bool => $v !== ''));
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
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return 'anonymous';
        }

        return (string) $authenticatedUser->id();
    }

    private function isForbiddenAgentActor(): bool
    {
        return $this->resolveAgentActorHandler->handle()->status() === ResolveAgentActorResult::STATUS_AUTHORIZED;
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
