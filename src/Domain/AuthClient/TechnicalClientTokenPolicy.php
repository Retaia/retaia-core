<?php

namespace App\Domain\AuthClient;

final class TechnicalClientTokenPolicy
{
    public function isForbiddenActor(string $clientKind): bool
    {
        return $clientKind === ClientKind::UI_WEB;
    }

    public function isForbiddenScope(string $clientKind, bool $mcpDisabled): bool
    {
        return $clientKind === ClientKind::MCP && $mcpDisabled;
    }
}
