<?php

namespace App\Controller\Api;

use App\Api\Service\IdempotencyService;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\User;
use App\Workflow\Service\BatchWorkflowService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WorkflowController
{
    public function __construct(
        private BatchWorkflowService $workflows,
        private AssetRepositoryInterface $assets,
        private IdempotencyService $idempotency,
        private Security $security,
        private TranslatorInterface $translator,
        private bool $bulkDecisionsEnabled,
    ) {
    }

    #[Route('/api/v1/batches/moves/preview', name: 'api_batches_moves_preview', methods: ['POST'])]
    public function previewMoves(Request $request): JsonResponse
    {
        if ($this->security->isGranted('ROLE_AGENT')) {
            return $this->forbiddenActor();
        }

        $uuids = $this->uuidListFromPayload($request);

        return new JsonResponse($this->workflows->previewMoves($uuids), Response::HTTP_OK);
    }

    #[Route('/api/v1/batches/moves', name: 'api_batches_moves_apply', methods: ['POST'])]
    public function applyMoves(Request $request): JsonResponse
    {
        if ($this->security->isGranted('ROLE_AGENT')) {
            return $this->forbiddenActor();
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($request): JsonResponse {
            $uuids = $this->uuidListFromPayload($request);

            return new JsonResponse($this->workflows->applyMoves($uuids), Response::HTTP_OK);
        });
    }

    #[Route('/api/v1/batches/moves/{batchId}', name: 'api_batches_moves_get', methods: ['GET'])]
    public function getBatch(string $batchId): JsonResponse
    {
        if ($this->security->isGranted('ROLE_AGENT')) {
            return $this->forbiddenActor();
        }

        $report = $this->workflows->getBatchReport($batchId);
        if ($report === null) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($report, Response::HTTP_OK);
    }

    #[Route('/api/v1/decisions/preview', name: 'api_decisions_preview', methods: ['POST'])]
    public function previewDecisions(Request $request): JsonResponse
    {
        if ($this->security->isGranted('ROLE_AGENT')) {
            return $this->forbiddenActor();
        }
        if (!$this->bulkDecisionsEnabled) {
            return $this->forbiddenScope();
        }

        $payload = $this->payload($request);
        $action = trim((string) ($payload['action'] ?? ''));
        $uuids = $this->uuidList($payload['uuids'] ?? null);
        if ($action === '' || $uuids === []) {
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'action and uuids are required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->workflows->previewDecisions($uuids, $action), Response::HTTP_OK);
    }

    #[Route('/api/v1/decisions/apply', name: 'api_decisions_apply', methods: ['POST'])]
    public function applyDecisions(Request $request): JsonResponse
    {
        if ($this->security->isGranted('ROLE_AGENT')) {
            return $this->forbiddenActor();
        }
        if (!$this->bulkDecisionsEnabled) {
            return $this->forbiddenScope();
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($request): JsonResponse {
            $payload = $this->payload($request);
            $action = trim((string) ($payload['action'] ?? ''));
            $uuids = $this->uuidList($payload['uuids'] ?? null);
            if ($action === '' || $uuids === []) {
                return new JsonResponse([
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'action and uuids are required',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return new JsonResponse($this->workflows->applyDecisions($uuids, $action), Response::HTTP_OK);
        });
    }

    #[Route('/api/v1/assets/{uuid}/purge/preview', name: 'api_assets_purge_preview', methods: ['POST'])]
    public function previewPurge(string $uuid): JsonResponse
    {
        if ($this->security->isGranted('ROLE_AGENT')) {
            return $this->forbiddenActor();
        }

        $asset = $this->assets->findByUuid($uuid);
        if ($asset === null) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->workflows->previewPurge($asset), Response::HTTP_OK);
    }

    #[Route('/api/v1/assets/{uuid}/purge', name: 'api_assets_purge_apply', methods: ['POST'])]
    public function purge(string $uuid, Request $request): JsonResponse
    {
        if ($this->security->isGranted('ROLE_AGENT')) {
            return $this->forbiddenActor();
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($uuid): JsonResponse {
            $asset = $this->assets->findByUuid($uuid);
            if ($asset === null) {
                return new JsonResponse([
                    'code' => 'NOT_FOUND',
                    'message' => $this->translator->trans('asset.error.not_found'),
                ], Response::HTTP_NOT_FOUND);
            }

            if (!$this->workflows->purge($asset)) {
                return new JsonResponse([
                    'code' => 'STATE_CONFLICT',
                    'message' => $this->translator->trans('asset.error.state_conflict'),
                ], Response::HTTP_CONFLICT);
            }

            return new JsonResponse([
                'uuid' => $asset->getUuid(),
                'state' => $asset->getState()->value,
            ], Response::HTTP_OK);
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
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getId() : 'anonymous';
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
