<?php

namespace App\Application\Derived;

use App\Application\Derived\Port\DerivedGateway;

final class CheckDerivedAssetExistsHandler
{
    public function __construct(
        private DerivedGateway $gateway,
    ) {
    }

    public function handle(string $assetUuid): bool
    {
        return $this->gateway->assetExists($assetUuid);
    }
}
