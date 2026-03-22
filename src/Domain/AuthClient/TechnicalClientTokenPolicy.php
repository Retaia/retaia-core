<?php

namespace App\Domain\AuthClient;

final class TechnicalClientTokenPolicy
{
    public function isForbiddenActor(string $clientKind): bool
    {
        return in_array($clientKind, [ClientKind::UI_WEB, ClientKind::MCP], true);
    }

    public function isForbiddenScope(string $clientKind, bool $mcpDisabled): bool
    {
        return false;
    }
}
