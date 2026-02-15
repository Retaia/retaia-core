<?php

namespace App\Application\AuthClient\Port;

interface ClientTokenMintRateLimiterGateway
{
    public function retryInSecondsOrNull(string $clientId, string $clientKind, string $remoteAddress): ?int;
}
