<?php

namespace App\Controller\Api;

use App\Application\Derived\DerivedEndpointResult;
use App\Application\Derived\DerivedEndpointsHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/assets/{uuid}/derived')]
final class DerivedController
{
    use ApiErrorResponderTrait;

    public function __construct(
        private DerivedEndpointsHandler $derivedEndpointsHandler,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/upload/init', name: 'api_assets_derived_upload_init', methods: ['POST'])]
    public function initUpload(string $uuid, Request $request): JsonResponse
    {
        $result = $this->derivedEndpointsHandler->initUpload($uuid, $this->payload($request));
        if ($result->status() === DerivedEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->forbiddenActor();
        }
        if ($result->status() === DerivedEndpointResult::STATUS_NOT_FOUND) {
            return $this->notFound();
        }
        if ($result->status() === DerivedEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'kind, content_type and size_bytes are required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
    }

    #[Route('/upload/part', name: 'api_assets_derived_upload_part', methods: ['POST'])]
    public function uploadPart(string $uuid, Request $request): JsonResponse
    {
        $result = $this->derivedEndpointsHandler->uploadPart($uuid, $this->payload($request));
        if ($result->status() === DerivedEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->forbiddenActor();
        }
        if ($result->status() === DerivedEndpointResult::STATUS_NOT_FOUND) {
            return $this->notFound();
        }
        if ($result->status() === DerivedEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'upload_id and part_number are required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($result->status() === DerivedEndpointResult::STATUS_STATE_CONFLICT) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => 'Upload session is invalid or already completed',
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['accepted' => true], Response::HTTP_OK);
    }

    #[Route('/upload/complete', name: 'api_assets_derived_upload_complete', methods: ['POST'])]
    public function completeUpload(string $uuid, Request $request): JsonResponse
    {
        $result = $this->derivedEndpointsHandler->completeUpload($uuid, $this->payload($request));
        if ($result->status() === DerivedEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->forbiddenActor();
        }
        if ($result->status() === DerivedEndpointResult::STATUS_NOT_FOUND) {
            return $this->notFound();
        }
        if ($result->status() === DerivedEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'upload_id and total_parts are required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($result->status() === DerivedEndpointResult::STATUS_STATE_CONFLICT) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => 'Upload completion requirements are not satisfied',
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
    }

    #[Route('', name: 'api_assets_derived_list', methods: ['GET'])]
    public function listDerived(string $uuid): JsonResponse
    {
        $result = $this->derivedEndpointsHandler->listDerived($uuid);
        if ($result->status() === DerivedEndpointResult::STATUS_NOT_FOUND) {
            return $this->notFound();
        }

        return new JsonResponse($result->payload() ?? ['items' => []], Response::HTTP_OK);
    }

    #[Route('/{kind}', name: 'api_assets_derived_get_kind', methods: ['GET'])]
    public function getByKind(string $uuid, string $kind): JsonResponse
    {
        $result = $this->derivedEndpointsHandler->getByKind($uuid, $kind);
        if ($result->status() === DerivedEndpointResult::STATUS_NOT_FOUND) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
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

    private function notFound(): JsonResponse
    {
        return $this->errorResponse('NOT_FOUND', $this->translator->trans('asset.error.not_found'), Response::HTTP_NOT_FOUND);
    }

    private function forbiddenActor(): JsonResponse
    {
        return $this->errorResponse('FORBIDDEN_ACTOR', $this->translator->trans('auth.error.forbidden_actor'), Response::HTTP_FORBIDDEN);
    }
}
