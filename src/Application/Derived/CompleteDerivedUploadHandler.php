<?php

namespace App\Application\Derived;

use App\Application\Derived\Port\DerivedGateway;

final class CompleteDerivedUploadHandler
{
    public function __construct(
        private DerivedGateway $gateway,
    ) {
    }

    public function handle(string $assetUuid, string $uploadId, int $totalParts): CompleteDerivedUploadResult
    {
        if (!$this->gateway->assetExists($assetUuid)) {
            return new CompleteDerivedUploadResult(CompleteDerivedUploadResult::STATUS_NOT_FOUND);
        }

        $derived = $this->gateway->completeUpload($assetUuid, $uploadId, $totalParts);
        if ($derived === null) {
            return new CompleteDerivedUploadResult(CompleteDerivedUploadResult::STATUS_STATE_CONFLICT);
        }

        return new CompleteDerivedUploadResult(CompleteDerivedUploadResult::STATUS_COMPLETED, $derived);
    }
}
