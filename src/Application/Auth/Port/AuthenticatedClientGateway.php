<?php

namespace App\Application\Auth\Port;

interface AuthenticatedClientGateway
{
    /**
     * @return array{client_id: string, client_kind: string}|null
     */
    public function currentClient(): ?array;
}
