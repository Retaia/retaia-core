<?php

namespace App\Application\Derived;

use App\Application\Derived\Port\DerivedGateway;

final class InitDerivedUploadHandler
{
    public function __construct(
        private DerivedGateway $gateway,
    ) {
    }

    public function handle(string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256): InitDerivedUploadResult
    {
        if (!$this->gateway->assetExists($assetUuid)) {
            return new InitDerivedUploadResult(InitDerivedUploadResult::STATUS_NOT_FOUND);
        }

        return new InitDerivedUploadResult(
            InitDerivedUploadResult::STATUS_INITIALIZED,
            $this->gateway->initUpload($assetUuid, $kind, $contentType, $sizeBytes, $sha256)
        );
    }
}
