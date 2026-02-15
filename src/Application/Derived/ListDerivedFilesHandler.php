<?php

namespace App\Application\Derived;

use App\Application\Derived\Port\DerivedGateway;

final class ListDerivedFilesHandler
{
    public function __construct(
        private DerivedGateway $gateway,
    ) {
    }

    public function handle(string $assetUuid): ListDerivedFilesResult
    {
        if (!$this->gateway->assetExists($assetUuid)) {
            return new ListDerivedFilesResult(ListDerivedFilesResult::STATUS_NOT_FOUND);
        }

        return new ListDerivedFilesResult(
            ListDerivedFilesResult::STATUS_FOUND,
            $this->gateway->listDerivedForAsset($assetUuid)
        );
    }
}
