<?php

namespace App\Infrastructure\Auth;

use App\Application\Auth\Port\PasswordResetRequestRateLimiterGateway as PasswordResetRequestRateLimiterGatewayPort;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class PasswordResetRequestRateLimiterGateway implements PasswordResetRequestRateLimiterGatewayPort
{
    public function __construct(
        private RateLimiterFactory $rateLimiterFactory,
    ) {
    }

    public function retryInSecondsOrNull(string $email, string $remoteAddress): ?int
    {
        $limiterKey = hash('sha256', mb_strtolower($email).'|'.$remoteAddress);
        $limit = $this->rateLimiterFactory->create($limiterKey)->consume(1);
        if ($limit->isAccepted()) {
            return null;
        }

        $retryAfter = $limit->getRetryAfter();

        return $retryAfter !== null ? max(1, $retryAfter->getTimestamp() - time()) : 60;
    }
}
