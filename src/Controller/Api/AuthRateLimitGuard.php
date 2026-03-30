<?php

namespace App\Controller\Api;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class AuthRateLimitGuard
{
    public function __construct(
        private AuthApiErrorResponder $errors,
        private AuthSessionHttpResponder $sessionResponder,
        #[Autowire(service: 'limiter.auth_refresh')]
        private RateLimiterFactory $refreshRateLimiter,
        #[Autowire(service: 'limiter.auth_2fa_manage')]
        private RateLimiterFactory $twoFactorManageRateLimiter,
    ) {
    }

    public function consumeRefresh(string $key): ?JsonResponse
    {
        $limit = $this->refreshRateLimiter->create($key)->consume(1);
        if ($limit->isAccepted()) {
            return null;
        }

        $retryAfter = $limit->getRetryAfter();
        $retryInSeconds = max(
            1,
            $retryAfter instanceof \DateTimeImmutable ? $retryAfter->getTimestamp() - time() : 1
        );

        return $this->sessionResponder->refreshTooManyAttempts($retryInSeconds);
    }

    public function consumeTwoFactorManage(string $userId, string $action, string $remoteAddress): ?JsonResponse
    {
        $limit = $this->twoFactorManageRateLimiter
            ->create(hash('sha256', $userId.'|'.$action.'|'.$remoteAddress))
            ->consume(1);
        if ($limit->isAccepted()) {
            return null;
        }

        $retryAfter = $limit->getRetryAfter();
        $retryInSeconds = max(
            1,
            $retryAfter instanceof \DateTimeImmutable ? $retryAfter->getTimestamp() - time() : 1
        );

        return $this->errors->tooManyAttempts('auth.error.too_many_2fa_attempts', $retryInSeconds);
    }
}
