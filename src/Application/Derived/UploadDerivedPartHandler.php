<?php

namespace App\Application\Derived;

use App\Application\Derived\Port\DerivedGateway;

final class UploadDerivedPartHandler
{
    public function __construct(
        private DerivedGateway $gateway,
    ) {
    }

    public function handle(string $assetUuid, string $uploadId, int $partNumber): UploadDerivedPartResult
    {
        if (!$this->gateway->assetExists($assetUuid)) {
            return new UploadDerivedPartResult(UploadDerivedPartResult::STATUS_NOT_FOUND);
        }

        if (!$this->gateway->addUploadPart($uploadId, $partNumber)) {
            return new UploadDerivedPartResult(UploadDerivedPartResult::STATUS_STATE_CONFLICT);
        }

        return new UploadDerivedPartResult(UploadDerivedPartResult::STATUS_ACCEPTED);
    }
}
