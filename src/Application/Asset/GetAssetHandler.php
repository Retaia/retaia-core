<?php

namespace App\Application\Asset;

use App\Application\Asset\Port\AssetReadGateway;

final class GetAssetHandler
{
    public function __construct(
        private AssetReadGateway $gateway,
    ) {
    }

    public function handle(string $uuid): GetAssetResult
    {
        $asset = $this->gateway->getByUuid($uuid);
        if ($asset === null) {
            return new GetAssetResult(GetAssetResult::STATUS_NOT_FOUND);
        }

        return new GetAssetResult(GetAssetResult::STATUS_FOUND, $asset);
    }
}
