<?php

namespace App\Controller\Api;

use App\Api\Service\AssetRequestPreconditionService;
use App\Api\Service\IdempotencyService;
use App\Application\Asset\AssetEndpointResult;
use App\Application\Asset\AssetEndpointsHandler;
use App\Controller\RequestPayloadTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/assets')]
final class AssetController
{
    use RequestPayloadTrait;

    public function __construct(
        private AssetEndpointsHandler $assetEndpointsHandler,
        private TranslatorInterface $translator,
        private IdempotencyService $idempotency,
        private AssetRequestPreconditionService $assetPreconditions,
    ) {
    }

    #[Route('', name: 'api_assets_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $states = $this->csvUpperList($request->query->get('state'));
        $mediaType = $request->query->get('media_type');
        $query = $request->query->get('q');
        $sort = $request->query->get('sort');
        $capturedAtFrom = $request->query->get('captured_at_from');
        $capturedAtTo = $request->query->get('captured_at_to');
        $cursor = $request->query->get('cursor');
        $tags = $this->csvList($request->query->get('tags'));
        $tagsMode = (string) $request->query->get('tags_mode', 'AND');
        $hasPreview = $this->nullableBooleanQuery($request, 'has_preview');
        $locationCountry = $this->optionalString($request->query->get('location_country'));
        $locationCity = $this->optionalString($request->query->get('location_city'));
        $geoBbox = $this->optionalString($request->query->get('geo_bbox'));
        $limit = max(1, (int) $request->query->get('limit', 50));

        $result = $this->assetEndpointsHandler->list(
            $states,
            is_string($mediaType) ? $mediaType : null,
            is_string($query) ? $query : null,
            is_string($sort) ? $sort : null,
            is_string($capturedAtFrom) ? $capturedAtFrom : null,
            is_string($capturedAtTo) ? $capturedAtTo : null,
            $limit,
            is_string($cursor) ? $cursor : null,
            $tags,
            $tagsMode,
            $hasPreview,
            $locationCountry,
            $locationCity,
            $geoBbox,
        );
        if ($result->status() === AssetEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'invalid assets list query parameters',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($result->status() === AssetEndpointResult::STATUS_FORBIDDEN_SCOPE) {
            return $this->forbiddenScopeResponse();
        }

        return $this->authenticatedJsonResponse($result->payload() ?? ['items' => [], 'next_cursor' => null], Response::HTTP_OK);
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

        $payload = $result->payload() ?? [];
        $response = $this->authenticatedJsonResponse($payload, Response::HTTP_OK);
        $etag = $payload['summary']['revision_etag'] ?? null;
        if (is_string($etag) && $etag !== '') {
            $response->headers->set('ETag', $etag);
        }

        return $response;
    }

    #[Route('/{uuid}', name: 'api_assets_patch', methods: ['PATCH'])]
    public function patch(string $uuid, Request $request): JsonResponse
    {
        if ($this->assetEndpointsHandler->isForbiddenAgentActor()) {
            return $this->forbiddenActorResponse();
        }
        $preconditionViolation = $this->assetPreconditions->violationResponse($request, $uuid);
        if ($preconditionViolation instanceof JsonResponse) {
            return $preconditionViolation;
        }

        $result = $this->assetEndpointsHandler->patch($uuid, $this->payload($request));
        if ($result->status() === AssetEndpointResult::STATUS_NOT_FOUND) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }
        if ($result->status() === AssetEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'invalid asset patch payload',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
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

        return $this->assetPreconditions->attachResponseEtagFromPayload(
            new JsonResponse($result->payload() ?? [], Response::HTTP_OK),
            $result->payload() ?? []
        );
    }

    #[Route('/{uuid}/reopen', name: 'api_assets_reopen', methods: ['POST'])]
    public function reopen(string $uuid, Request $request): JsonResponse
    {
        if ($this->assetEndpointsHandler->isForbiddenAgentActor()) {
            return $this->forbiddenActorResponse();
        }
        $preconditionViolation = $this->assetPreconditions->violationResponse($request, $uuid);
        if ($preconditionViolation instanceof JsonResponse) {
            return $preconditionViolation;
        }

        return $this->assetActionResponse($this->assetEndpointsHandler->reopen($uuid));
    }

    #[Route('/{uuid}/reprocess', name: 'api_assets_reprocess', methods: ['POST'])]
    public function reprocess(string $uuid, Request $request): JsonResponse
    {
        if ($this->assetEndpointsHandler->isForbiddenAgentActor()) {
            return $this->forbiddenActorResponse();
        }
        $preconditionViolation = $this->assetPreconditions->violationResponse($request, $uuid);
        if ($preconditionViolation instanceof JsonResponse) {
            return $preconditionViolation;
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($uuid): JsonResponse {
            return $this->assetActionResponse($this->assetEndpointsHandler->reprocess($uuid));
        });
    }

    private function assetActionResponse(AssetEndpointResult $result): JsonResponse
    {
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

        return $this->assetPreconditions->attachResponseEtagFromPayload(
            new JsonResponse($result->payload() ?? [], Response::HTTP_OK),
            $result->payload() ?? []
        );
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
     * @param array<string, mixed> $payload
     */
    private function authenticatedJsonResponse(array $payload, int $status): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
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

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function csvUpperList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $items = array_map(static fn (string $item): string => strtoupper(trim($item)), explode(',', $value));

        return array_values(array_unique(array_filter($items, static fn (string $item): bool => $item !== '')));
    }

    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableBooleanQuery(Request $request, string $key): ?bool
    {
        if (!$request->query->has($key)) {
            return null;
        }

        $value = $request->query->get($key);
        if (is_bool($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

}
