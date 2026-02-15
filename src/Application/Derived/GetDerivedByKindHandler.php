<?php

namespace App\Application\Derived;

use App\Application\Derived\Port\DerivedGateway;

final class GetDerivedByKindHandler
{
    public function __construct(
        private DerivedGateway $gateway,
    ) {
    }

    public function handle(string $assetUuid, string $kind): GetDerivedByKindResult
    {
        if (!$this->gateway->assetExists($assetUuid)) {
            return new GetDerivedByKindResult(GetDerivedByKindResult::STATUS_ASSET_NOT_FOUND);
        }

        $derived = $this->gateway->findDerivedByAssetAndKind($assetUuid, $kind);
        if ($derived === null) {
            return new GetDerivedByKindResult(GetDerivedByKindResult::STATUS_DERIVED_NOT_FOUND);
        }

        return new GetDerivedByKindResult(GetDerivedByKindResult::STATUS_FOUND, $derived);
    }
}
