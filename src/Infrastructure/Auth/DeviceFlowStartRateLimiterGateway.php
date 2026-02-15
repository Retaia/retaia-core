<?php

namespace App\Infrastructure\Auth;

use App\Application\AuthClient\Port\DeviceFlowStartRateLimiterGateway as DeviceFlowStartRateLimiterGatewayPort;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class DeviceFlowStartRateLimiterGateway implements DeviceFlowStartRateLimiterGatewayPort
{
    public function __construct(
        private RateLimiterFactory $rateLimiterFactory,
    ) {
    }

    public function retryInSecondsOrNull(string $clientKind, string $remoteAddress): ?int
    {
        $limiterKey = hash('sha256', $clientKind.'|'.$remoteAddress);
        $limit = $this->rateLimiterFactory->create($limiterKey)->consume(1);
        if ($limit->isAccepted()) {
            return null;
        }

        $retryAfter = $limit->getRetryAfter();

        return $retryAfter !== null ? max(1, $retryAfter->getTimestamp() - time()) : 60;
    }
}

