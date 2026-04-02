<?php

namespace App\Controller\Api;

use App\Api\Service\AssetRequestPreconditionService;
use App\Application\Asset\AssetEndpointResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AssetHttpResponder
{
    public function __construct(
        private TranslatorInterface $translator,
        private AssetRequestPreconditionService $assetPreconditions,
    ) {
    }

    public function listResult(AssetEndpointResult $result): JsonResponse
    {
        if ($result->status() === AssetEndpointResult::STATUS_VALIDATION_FAILED) {
            return $this->errorResponse('VALIDATION_FAILED', 'asset.error.invalid_list_query', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($result->status() === AssetEndpointResult::STATUS_FORBIDDEN_SCOPE) {
            return $this->forbiddenScope();
        }

        return $this->authenticatedJsonResponse($result->payload() ?? ['items' => [], 'next_cursor' => null], Response::HTTP_OK);
    }

    public function getOneResult(AssetEndpointResult $result): JsonResponse
    {
        if ($result->status() === AssetEndpointResult::STATUS_NOT_FOUND) {
            return $this->notFound();
        }

        $payload = $result->payload() ?? [];
        $response = $this->authenticatedJsonResponse($payload, Response::HTTP_OK);
        $etag = $payload['summary']['revision_etag'] ?? null;
        if (is_string($etag) && $etag !== '') {
            $response->headers->set('ETag', $etag);
        }

        return $response;
    }

    public function patchResult(AssetEndpointResult $result): JsonResponse
    {
        if ($result->status() === AssetEndpointResult::STATUS_NOT_FOUND) {
            return $this->notFound();
        }
        if ($result->status() === AssetEndpointResult::STATUS_VALIDATION_FAILED) {
            return $this->errorResponse('VALIDATION_FAILED', 'asset.error.invalid_patch_payload', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($result->status() === AssetEndpointResult::STATUS_PURGED_READ_ONLY) {
            return $this->errorResponse('STATE_CONFLICT', 'asset.error.purged_read_only', Response::HTTP_GONE);
        }
        if ($result->status() === AssetEndpointResult::STATUS_STATE_CONFLICT) {
            return $this->stateConflict();
        }

        return $this->assetPreconditions->attachResponseEtagFromPayload(
            new JsonResponse($result->payload() ?? [], Response::HTTP_OK),
            $result->payload() ?? []
        );
    }

    public function assetActionResult(AssetEndpointResult $result): JsonResponse
    {
        if ($result->status() === AssetEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->forbiddenActor();
        }
        if ($result->status() === AssetEndpointResult::STATUS_NOT_FOUND) {
            return $this->notFound();
        }
        if ($result->status() === AssetEndpointResult::STATUS_STATE_CONFLICT) {
            return $this->stateConflict();
        }

        return $this->assetPreconditions->attachResponseEtagFromPayload(
            new JsonResponse($result->payload() ?? [], Response::HTTP_OK),
            $result->payload() ?? []
        );
    }

    public function forbiddenActor(): JsonResponse
    {
        return $this->errorResponse('FORBIDDEN_ACTOR', 'auth.error.forbidden_actor', Response::HTTP_FORBIDDEN);
    }

    public function forbiddenScope(): JsonResponse
    {
        return $this->errorResponse('FORBIDDEN_SCOPE', 'auth.error.forbidden_scope', Response::HTTP_FORBIDDEN);
    }

    private function notFound(): JsonResponse
    {
        return $this->errorResponse('NOT_FOUND', 'asset.error.not_found', Response::HTTP_NOT_FOUND);
    }

    private function stateConflict(): JsonResponse
    {
        return $this->errorResponse('STATE_CONFLICT', 'asset.error.state_conflict', Response::HTTP_CONFLICT);
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

    private function errorResponse(string $code, string $messageKey, int $status): JsonResponse
    {
        return ApiErrorResponseFactory::create($code, $this->translator->trans($messageKey), $status);
    }
}
