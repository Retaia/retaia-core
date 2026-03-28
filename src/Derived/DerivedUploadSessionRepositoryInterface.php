<?php

namespace App\Derived;

interface DerivedUploadSessionRepositoryInterface
{
    public function create(string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256): DerivedUploadSession;

    public function find(string $uploadId): ?DerivedUploadSession;

    public function updateHighestPartCount(string $uploadId, int $partNumber): void;

    public function markCompleted(string $uploadId): void;
}
