<?php

namespace App\Controller\Api;

use App\Api\Service\AssetRequestPreconditionService;
use App\Api\Service\SignedAgentRequestValidator;
use App\Application\Derived\DerivedEndpointResult;
use App\Application\Derived\DerivedEndpointsHandler;
use App\Controller\RequestPayloadTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/assets/{uuid}/derived')]
final class DerivedController
{
    use ApiErrorResponderTrait;
    use RequestPayloadTrait;

    public function __construct(
        private DerivedEndpointsHandler $derivedEndpointsHandler,
        private TranslatorInterface $translator,
        private AssetRequestPreconditionService $assetPreconditions,
        private SignedAgentRequestValidator $signedAgentRequestValidator,
    ) {
    }

    #[Route('/upload/init', name: 'api_assets_derived_upload_init', methods: ['POST'])]
    public function initUpload(string $uuid, Request $request): JsonResponse
    {
        if ($this->derivedEndpointsHandler->isForbiddenAgentActor()) {
            return $this->forbiddenActor();
        }
        $preconditionViolation = $this->assetPreconditions->violationResponse($request, $uuid);
        if ($preconditionViolation instanceof JsonResponse) {
            return $preconditionViolation;
        }
        $signatureViolation = $this->signedAgentRequestValidator->violationResponse($request);
        if ($signatureViolation instanceof JsonResponse) {
            return $signatureViolation;
        }

        $result = $this->derivedEndpointsHandler->initUpload($uuid, $this->payload($request));
        if ($result->status() === DerivedEndpointResult::STATUS_NOT_FOUND) {
            return $this->notFound();
        }
        if ($result->status() === DerivedEndpointResult::STATUS_VALIDATION_FAILED) {
            return $this->errorResponse('VALIDATION_FAILED', $this->translator->trans('derived.error.init_upload_payload_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
    }

    #[Route('/upload/part', name: 'api_assets_derived_upload_part', methods: ['POST'])]
    public function uploadPart(string $uuid, Request $request): JsonResponse
    {
        if ($this->derivedEndpointsHandler->isForbiddenAgentActor()) {
            return $this->forbiddenActor();
        }
        $preconditionViolation = $this->assetPreconditions->violationResponse($request, $uuid);
        if ($preconditionViolation instanceof JsonResponse) {
            return $preconditionViolation;
        }
        $signatureViolation = $this->signedAgentRequestValidator->violationResponse($request);
        if ($signatureViolation instanceof JsonResponse) {
            return $signatureViolation;
        }

        $result = $this->derivedEndpointsHandler->uploadPart($uuid, $this->payload($request));
        if ($result->status() === DerivedEndpointResult::STATUS_NOT_FOUND) {
            return $this->notFound();
        }
        if ($result->status() === DerivedEndpointResult::STATUS_VALIDATION_FAILED) {
            return $this->errorResponse('VALIDATION_FAILED', $this->translator->trans('derived.error.upload_part_payload_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($result->status() === DerivedEndpointResult::STATUS_STATE_CONFLICT) {
            return $this->errorResponse('STATE_CONFLICT', $this->translator->trans('derived.error.upload_session_conflict'), Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['accepted' => true], Response::HTTP_OK);
    }

    #[Route('/upload/complete', name: 'api_assets_derived_upload_complete', methods: ['POST'])]
    public function completeUpload(string $uuid, Request $request): JsonResponse
    {
        if ($this->derivedEndpointsHandler->isForbiddenAgentActor()) {
            return $this->forbiddenActor();
        }
        $preconditionViolation = $this->assetPreconditions->violationResponse($request, $uuid);
        if ($preconditionViolation instanceof JsonResponse) {
            return $preconditionViolation;
        }
        $signatureViolation = $this->signedAgentRequestValidator->violationResponse($request);
        if ($signatureViolation instanceof JsonResponse) {
            return $signatureViolation;
        }

        $result = $this->derivedEndpointsHandler->completeUpload($uuid, $this->payload($request));
        if ($result->status() === DerivedEndpointResult::STATUS_NOT_FOUND) {
            return $this->notFound();
        }
        if ($result->status() === DerivedEndpointResult::STATUS_VALIDATION_FAILED) {
            return $this->errorResponse('VALIDATION_FAILED', $this->translator->trans('derived.error.complete_upload_payload_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($result->status() === DerivedEndpointResult::STATUS_STATE_CONFLICT) {
            return $this->errorResponse('STATE_CONFLICT', $this->translator->trans('derived.error.upload_completion_conflict'), Response::HTTP_CONFLICT);
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
            return $this->errorResponse('NOT_FOUND', $this->translator->trans('asset.error.not_found'), Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($result->payload() ?? [], Response::HTTP_OK);
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
