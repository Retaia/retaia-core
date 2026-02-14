<?php

namespace App\Domain\AuthClient;

final class TechnicalClientAdminPolicy
{
    public function isRevokeForbiddenScope(?string $clientKind): bool
    {
        return $clientKind === 'UI_RUST';
    }
}
