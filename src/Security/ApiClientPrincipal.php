<?php

namespace App\Security;

use App\Domain\AuthClient\ClientKind;
use Symfony\Component\Security\Core\User\UserInterface;

final class ApiClientPrincipal implements UserInterface
{
    public function __construct(
        private string $clientId,
        private string $clientKind,
    ) {
    }

    public function getUserIdentifier(): string
    {
        return 'client:'.$this->clientId;
    }

    /**
     * @return array<int, string>
     */
    public function getRoles(): array
    {
        return match ($this->clientKind) {
            ClientKind::AGENT => ['ROLE_AGENT'],
            ClientKind::MCP => ['ROLE_MCP'],
            default => [],
        };
    }

    public function eraseCredentials(): void
    {
    }
}
