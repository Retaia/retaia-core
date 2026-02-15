<?php

namespace App\Controller\Api;

use App\Api\Service\IdempotencyService;
use App\Application\Asset\AssetEndpointResult;
use App\Application\Asset\AssetEndpointsHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/assets')]
final class AssetController
{
    public function __construct(
        private AssetEndpointsHandler $assetEndpointsHandler,
        private TranslatorInterface $translator,
        private IdempotencyService $idempotency,
    ) {
    }

    #[Route('', name: 'api_assets_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $state = $request->query->get('state');
        $mediaType = $request->query->get('media_type');
        $query = $request->query->get('q');
        $suggestedTags = $this->csvList($request->query->get('suggested_tags'));
        $suggestedTagsMode = (string) $request->query->get('suggested_tags_mode', 'AND');
        $limit = max(1, (int) $request->query->get('limit', 50));

        $result = $this->assetEndpointsHandler->list(
            is_string($state) ? $state : null,
            is_string($mediaType) ? $mediaType : null,
            is_string($query) ? $query : null,
            $limit,
            $suggestedTags,
            $suggestedTagsMode,
        );
        if ($result->status() === AssetEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'suggested_tags_mode must be AND or OR',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($result->status() === AssetEndpointResult::STATUS_FORBIDDEN_SCOPE) {
            return $this->forbiddenScopeResponse();
        }

        return new JsonResponse($result->payload() ?? ['items' => [], 'next_cursor' => null], Response::HTTP_OK);
    }

    #[Route('/{uuid}', name: 'api_assets_get', methods: ['GET'])]
    public function getOne(string $uuid): JsonResponse
    {
        $result = $this->assetEndpointsHandler->getOne($uuid);
        if ($result->status() === AssetEndpointResult::STATUS_NOT_FOUND) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
    }

    #[Route('/{uuid}', name: 'api_assets_patch', methods: ['PATCH'])]
    public function patch(string $uuid, Request $request): JsonResponse
    {
        $result = $this->assetEndpointsHandler->patch($uuid, $this->payload($request));
        if ($result->status() === AssetEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->forbiddenActorResponse();
        }
        if ($result->status() === AssetEndpointResult::STATUS_NOT_FOUND) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        if ($result->status() === AssetEndpointResult::STATUS_PURGED_READ_ONLY) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => $this->translator->trans('asset.error.purged_read_only'),
            ], Response::HTTP_GONE);
        }

        if ($result->status() === AssetEndpointResult::STATUS_STATE_CONFLICT) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => $this->translator->trans('asset.error.state_conflict'),
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
    }

    #[Route('/{uuid}/decision', name: 'api_assets_decision', methods: ['POST'])]
    public function decision(string $uuid, Request $request): JsonResponse
    {
        if ($this->assetEndpointsHandler->isForbiddenAgentActor()) {
            return $this->forbiddenActorResponse();
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($uuid, $request): JsonResponse {
            $result = $this->assetEndpointsHandler->decision($uuid, $this->payload($request));
            if ($result->status() === AssetEndpointResult::STATUS_FORBIDDEN_ACTOR) {
                return $this->forbiddenActorResponse();
            }
            if ($result->status() === AssetEndpointResult::STATUS_NOT_FOUND) {
                return new JsonResponse([
                    'code' => 'NOT_FOUND',
                    'message' => $this->translator->trans('asset.error.not_found'),
                ], Response::HTTP_NOT_FOUND);
            }

            if ($result->status() === AssetEndpointResult::STATUS_STATE_CONFLICT) {
                return new JsonResponse([
                    'code' => 'STATE_CONFLICT',
                    'message' => $this->translator->trans('asset.error.state_conflict'),
                ], Response::HTTP_CONFLICT);
            }

            if ($result->status() === AssetEndpointResult::STATUS_VALIDATION_FAILED) {
                return new JsonResponse([
                    'code' => 'VALIDATION_FAILED',
                    'message' => $this->translator->trans('asset.error.decision_action_required'),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
        });
    }

    #[Route('/{uuid}/reopen', name: 'api_assets_reopen', methods: ['POST'])]
    public function reopen(string $uuid): JsonResponse
    {
        $result = $this->assetEndpointsHandler->reopen($uuid);
        if ($result->status() === AssetEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->forbiddenActorResponse();
        }
        if ($result->status() === AssetEndpointResult::STATUS_NOT_FOUND) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        if ($result->status() === AssetEndpointResult::STATUS_STATE_CONFLICT) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => $this->translator->trans('asset.error.state_conflict'),
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
    }

    #[Route('/{uuid}/reprocess', name: 'api_assets_reprocess', methods: ['POST'])]
    public function reprocess(string $uuid, Request $request): JsonResponse
    {
        if ($this->assetEndpointsHandler->isForbiddenAgentActor()) {
            return $this->forbiddenActorResponse();
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($uuid): JsonResponse {
            $result = $this->assetEndpointsHandler->reprocess($uuid);
            if ($result->status() === AssetEndpointResult::STATUS_FORBIDDEN_ACTOR) {
                return $this->forbiddenActorResponse();
            }
            if ($result->status() === AssetEndpointResult::STATUS_NOT_FOUND) {
                return new JsonResponse([
                    'code' => 'NOT_FOUND',
                    'message' => $this->translator->trans('asset.error.not_found'),
                ], Response::HTTP_NOT_FOUND);
            }

            if ($result->status() === AssetEndpointResult::STATUS_STATE_CONFLICT) {
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

    private function forbiddenActorResponse(): JsonResponse
    {
        return new JsonResponse([
            'code' => 'FORBIDDEN_ACTOR',
            'message' => $this->translator->trans('auth.error.forbidden_actor'),
        ], Response::HTTP_FORBIDDEN);
    }

    private function forbiddenScopeResponse(): JsonResponse
    {
        return new JsonResponse([
            'code' => 'FORBIDDEN_SCOPE',
            'message' => $this->translator->trans('auth.error.forbidden_scope'),
        ], Response::HTTP_FORBIDDEN);
    }

    private function actorId(): string
    {
        return $this->assetEndpointsHandler->actorId();
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function csvList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $items = array_map(static fn (string $item): string => mb_strtolower(trim($item)), explode(',', $value));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

}
