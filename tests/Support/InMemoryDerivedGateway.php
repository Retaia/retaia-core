<?php

namespace App\Tests\Support;

use App\Application\Derived\Port\DerivedGateway;

final class InMemoryDerivedGateway implements DerivedGateway
{
    public bool $assetExists = true;
    /** @var array<int, bool> */
    public array $assetExistsSequence = [];
    /** @var array<string, mixed> */
    public array $initSession = ['upload_id' => 'u1'];
    public bool $addPartSuccess = true;
    /** @var array<string, mixed>|null */
    public ?array $completeResult = ['id' => 'd1'];
    /** @var array<int, array<string, mixed>> */
    public array $listItems = [];
    /** @var array<string, mixed>|null */
    public ?array $derivedByKind = ['id' => 'd1'];

    public function assetExists(string $assetUuid): bool
    {
        if ($this->assetExistsSequence !== []) {
            /** @var bool $next */
            $next = array_shift($this->assetExistsSequence);

            return $next;
        }

        return $this->assetExists;
    }

    public function initUpload(string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256): array
    {
        return $this->initSession;
    }

    public function addUploadPart(string $uploadId, int $partNumber): bool
    {
        return $this->addPartSuccess;
    }

    public function completeUpload(string $assetUuid, string $uploadId, int $totalParts): ?array
    {
        return $this->completeResult;
    }

    public function listDerivedForAsset(string $assetUuid): array
    {
        return $this->listItems;
    }

    public function findDerivedByAssetAndKind(string $assetUuid, string $kind): ?array
    {
        return $this->derivedByKind;
    }
}

