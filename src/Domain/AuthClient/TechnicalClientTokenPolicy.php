<?php

namespace App\Domain\AuthClient;

final class TechnicalClientTokenPolicy
{
    public function isForbiddenActor(string $clientKind): bool
    {
        return $clientKind === 'UI_RUST';
    }

    public function isForbiddenScope(string $clientKind, bool $mcpDisabled): bool
    {
        return $clientKind === 'MCP' && $mcpDisabled;
    }
}
