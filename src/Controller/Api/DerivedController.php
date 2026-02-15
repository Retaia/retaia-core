<?php

namespace App\Controller\Api;

use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Auth\ResolveAgentActorResult;
use App\Application\Derived\CheckDerivedAssetExistsHandler;
use App\Application\Derived\CompleteDerivedUploadHandler;
use App\Application\Derived\CompleteDerivedUploadResult;
use App\Application\Derived\GetDerivedByKindHandler;
use App\Application\Derived\GetDerivedByKindResult;
use App\Application\Derived\InitDerivedUploadHandler;
use App\Application\Derived\InitDerivedUploadResult;
use App\Application\Derived\ListDerivedFilesHandler;
use App\Application\Derived\UploadDerivedPartHandler;
use App\Application\Derived\UploadDerivedPartResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/assets/{uuid}/derived')]
final class DerivedController
{
    public function __construct(
        private ResolveAgentActorHandler $resolveAgentActorHandler,
        private CheckDerivedAssetExistsHandler $checkDerivedAssetExistsHandler,
        private InitDerivedUploadHandler $initDerivedUploadHandler,
        private UploadDerivedPartHandler $uploadDerivedPartHandler,
        private CompleteDerivedUploadHandler $completeDerivedUploadHandler,
        private ListDerivedFilesHandler $listDerivedFilesHandler,
        private GetDerivedByKindHandler $getDerivedByKindHandler,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/upload/init', name: 'api_assets_derived_upload_init', methods: ['POST'])]
    public function initUpload(string $uuid, Request $request): JsonResponse
    {
        $agentActor = $this->resolveAgentActorHandler->handle();
        if ($agentActor->status() === ResolveAgentActorResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->forbiddenActor();
        }
        if (!$this->checkDerivedAssetExistsHandler->handle($uuid)) {
            return $this->notFound();
        }

        $payload = $this->payload($request);
        $kind = trim((string) ($payload['kind'] ?? ''));
        $contentType = trim((string) ($payload['content_type'] ?? ''));
        $sizeBytes = (int) ($payload['size_bytes'] ?? 0);
        $sha256 = isset($payload['sha256']) ? trim((string) $payload['sha256']) : null;

        if ($kind === '' || $contentType === '' || $sizeBytes <= 0) {
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'kind, content_type and size_bytes are required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->initDerivedUploadHandler->handle($uuid, $kind, $contentType, $sizeBytes, $sha256 !== '' ? $sha256 : null);
        if ($result->status() === InitDerivedUploadResult::STATUS_NOT_FOUND) {
            return $this->notFound();
        }

        return new JsonResponse($result->session() ?? [], Response::HTTP_OK);
    }

    #[Route('/upload/part', name: 'api_assets_derived_upload_part', methods: ['POST'])]
    public function uploadPart(string $uuid, Request $request): JsonResponse
    {
        $agentActor = $this->resolveAgentActorHandler->handle();
        if ($agentActor->status() === ResolveAgentActorResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->forbiddenActor();
        }
        if (!$this->checkDerivedAssetExistsHandler->handle($uuid)) {
            return $this->notFound();
        }

        $payload = $this->payload($request);
        $uploadId = trim((string) ($payload['upload_id'] ?? ''));
        $partNumber = (int) ($payload['part_number'] ?? 0);

        if ($uploadId === '' || $partNumber <= 0) {
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'upload_id and part_number are required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->uploadDerivedPartHandler->handle($uuid, $uploadId, $partNumber);
        if ($result->status() === UploadDerivedPartResult::STATUS_NOT_FOUND) {
            return $this->notFound();
        }
        if ($result->status() === UploadDerivedPartResult::STATUS_STATE_CONFLICT) {
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
        $agentActor = $this->resolveAgentActorHandler->handle();
        if ($agentActor->status() === ResolveAgentActorResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->forbiddenActor();
        }
        if (!$this->checkDerivedAssetExistsHandler->handle($uuid)) {
            return $this->notFound();
        }

        $payload = $this->payload($request);
        $uploadId = trim((string) ($payload['upload_id'] ?? ''));
        $totalParts = (int) ($payload['total_parts'] ?? 0);
        if ($uploadId === '' || $totalParts <= 0) {
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'upload_id and total_parts are required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->completeDerivedUploadHandler->handle($uuid, $uploadId, $totalParts);
        if ($result->status() === CompleteDerivedUploadResult::STATUS_NOT_FOUND) {
            return $this->notFound();
        }
        if ($result->status() === CompleteDerivedUploadResult::STATUS_STATE_CONFLICT) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => 'Upload completion requirements are not satisfied',
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($result->derived() ?? [], Response::HTTP_OK);
    }

    #[Route('', name: 'api_assets_derived_list', methods: ['GET'])]
    public function listDerived(string $uuid): JsonResponse
    {
        if (!$this->checkDerivedAssetExistsHandler->handle($uuid)) {
            return $this->notFound();
        }

        $result = $this->listDerivedFilesHandler->handle($uuid);

        return new JsonResponse([
            'items' => $result->items() ?? [],
        ], Response::HTTP_OK);
    }

    #[Route('/{kind}', name: 'api_assets_derived_get_kind', methods: ['GET'])]
    public function getByKind(string $uuid, string $kind): JsonResponse
    {
        if (!$this->checkDerivedAssetExistsHandler->handle($uuid)) {
            return $this->notFound();
        }

        $result = $this->getDerivedByKindHandler->handle($uuid, $kind);

        if ($result->status() === GetDerivedByKindResult::STATUS_DERIVED_NOT_FOUND) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($result->derived() ?? [], Response::HTTP_OK);
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
        return new JsonResponse([
            'code' => 'NOT_FOUND',
            'message' => $this->translator->trans('asset.error.not_found'),
        ], Response::HTTP_NOT_FOUND);
    }

    private function forbiddenActor(): JsonResponse
    {
        return new JsonResponse([
            'code' => 'FORBIDDEN_ACTOR',
            'message' => $this->translator->trans('auth.error.forbidden_actor'),
        ], Response::HTTP_FORBIDDEN);
    }
}
