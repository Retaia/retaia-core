<?php

namespace App\Application\Derived;

use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Auth\ResolveAgentActorResult;

final class DerivedEndpointsHandler
{
    public function __construct(
        private ResolveAgentActorHandler $resolveAgentActorHandler,
        private CheckDerivedAssetExistsHandler $checkDerivedAssetExistsHandler,
        private InitDerivedUploadHandler $initDerivedUploadHandler,
        private UploadDerivedPartHandler $uploadDerivedPartHandler,
        private CompleteDerivedUploadHandler $completeDerivedUploadHandler,
        private ListDerivedFilesHandler $listDerivedFilesHandler,
        private GetDerivedByKindHandler $getDerivedByKindHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function initUpload(string $uuid, array $payload): DerivedEndpointResult
    {
        if ($this->isForbiddenAgentActor()) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }
        if (!$this->checkDerivedAssetExistsHandler->handle($uuid)) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_NOT_FOUND);
        }

        $kind = trim((string) ($payload['kind'] ?? ''));
        $contentType = trim((string) ($payload['content_type'] ?? ''));
        $sizeBytes = (int) ($payload['size_bytes'] ?? 0);
        $sha256 = isset($payload['sha256']) ? trim((string) $payload['sha256']) : null;

        if ($kind === '' || $contentType === '' || $sizeBytes <= 0) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_VALIDATION_FAILED);
        }

        $result = $this->initDerivedUploadHandler->handle($uuid, $kind, $contentType, $sizeBytes, $sha256 !== '' ? $sha256 : null);
        if ($result->status() === InitDerivedUploadResult::STATUS_NOT_FOUND) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_NOT_FOUND);
        }

        return new DerivedEndpointResult(DerivedEndpointResult::STATUS_SUCCESS, $result->session());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function uploadPart(string $uuid, array $payload): DerivedEndpointResult
    {
        if ($this->isForbiddenAgentActor()) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }
        if (!$this->checkDerivedAssetExistsHandler->handle($uuid)) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_NOT_FOUND);
        }

        $uploadId = trim((string) ($payload['upload_id'] ?? ''));
        $partNumber = (int) ($payload['part_number'] ?? 0);
        if ($uploadId === '' || $partNumber <= 0) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_VALIDATION_FAILED);
        }

        $result = $this->uploadDerivedPartHandler->handle($uuid, $uploadId, $partNumber);
        if ($result->status() === UploadDerivedPartResult::STATUS_NOT_FOUND) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_NOT_FOUND);
        }
        if ($result->status() === UploadDerivedPartResult::STATUS_STATE_CONFLICT) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_STATE_CONFLICT);
        }

        return new DerivedEndpointResult(DerivedEndpointResult::STATUS_SUCCESS, ['accepted' => true]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function completeUpload(string $uuid, array $payload): DerivedEndpointResult
    {
        if ($this->isForbiddenAgentActor()) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }
        if (!$this->checkDerivedAssetExistsHandler->handle($uuid)) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_NOT_FOUND);
        }

        $uploadId = trim((string) ($payload['upload_id'] ?? ''));
        $totalParts = (int) ($payload['total_parts'] ?? 0);
        if ($uploadId === '' || $totalParts <= 0) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_VALIDATION_FAILED);
        }

        $result = $this->completeDerivedUploadHandler->handle($uuid, $uploadId, $totalParts);
        if ($result->status() === CompleteDerivedUploadResult::STATUS_NOT_FOUND) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_NOT_FOUND);
        }
        if ($result->status() === CompleteDerivedUploadResult::STATUS_STATE_CONFLICT) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_STATE_CONFLICT);
        }

        return new DerivedEndpointResult(DerivedEndpointResult::STATUS_SUCCESS, $result->derived());
    }

    public function listDerived(string $uuid): DerivedEndpointResult
    {
        if (!$this->checkDerivedAssetExistsHandler->handle($uuid)) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_NOT_FOUND);
        }

        $result = $this->listDerivedFilesHandler->handle($uuid);

        return new DerivedEndpointResult(DerivedEndpointResult::STATUS_SUCCESS, ['items' => $result->items() ?? []]);
    }

    public function getByKind(string $uuid, string $kind): DerivedEndpointResult
    {
        if (!$this->checkDerivedAssetExistsHandler->handle($uuid)) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_NOT_FOUND);
        }

        $result = $this->getDerivedByKindHandler->handle($uuid, $kind);
        if ($result->status() === GetDerivedByKindResult::STATUS_DERIVED_NOT_FOUND) {
            return new DerivedEndpointResult(DerivedEndpointResult::STATUS_NOT_FOUND);
        }

        return new DerivedEndpointResult(DerivedEndpointResult::STATUS_SUCCESS, $result->derived());
    }

    private function isForbiddenAgentActor(): bool
    {
        return $this->resolveAgentActorHandler->handle()->status() === ResolveAgentActorResult::STATUS_FORBIDDEN_ACTOR;
    }
}
