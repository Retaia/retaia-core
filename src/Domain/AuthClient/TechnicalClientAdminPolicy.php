<?php

namespace App\Domain\AuthClient;

final class TechnicalClientAdminPolicy
{
    public function isRevokeForbiddenScope(?string $clientKind): bool
    {
        return $clientKind === ClientKind::UI_WEB;
    }
}
