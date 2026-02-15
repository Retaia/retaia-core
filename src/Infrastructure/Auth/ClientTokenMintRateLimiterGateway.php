<?php

namespace App\Infrastructure\Auth;

use App\Application\AuthClient\Port\ClientTokenMintRateLimiterGateway as ClientTokenMintRateLimiterGatewayPort;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class ClientTokenMintRateLimiterGateway implements ClientTokenMintRateLimiterGatewayPort
{
    public function __construct(
        private RateLimiterFactory $rateLimiterFactory,
    ) {
    }

    public function retryInSecondsOrNull(string $clientId, string $clientKind, string $remoteAddress): ?int
    {
        $limiterKey = hash('sha256', mb_strtolower($clientId).'|'.$clientKind.'|'.$remoteAddress);
        $limit = $this->rateLimiterFactory->create($limiterKey)->consume(1);
        if ($limit->isAccepted()) {
            return null;
        }

        $retryAfter = $limit->getRetryAfter();

        return $retryAfter !== null ? max(1, $retryAfter->getTimestamp() - time()) : 60;
    }
}
