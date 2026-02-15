<?php

namespace App\Application\Auth\Port;

interface AuthenticatedUserGateway
{
    /**
     * @return array{id: string, email: string, roles: array<int, string>}|null
     */
    public function currentUser(): ?array;
}
