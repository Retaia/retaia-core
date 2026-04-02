<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApiLoginSecondFactorAttemptLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.auth_2fa_challenge')]
        private RateLimiterFactory $twoFactorChallengeRateLimiter,
        private TranslatorInterface $translator,
    ) {
    }

    public function consume(string $userId, string $remoteAddress): bool
    {
        $limit = $this->twoFactorChallengeRateLimiter
            ->create(hash('sha256', $userId.'|'.$remoteAddress.'|login'))
            ->consume(1);

        return $limit->isAccepted();
    }

    public function tooManyAttemptsResponse(): JsonResponse
    {
        return new JsonResponse(
            ['code' => 'TOO_MANY_ATTEMPTS', 'message' => $this->translator->trans('auth.error.too_many_2fa_attempts')],
            Response::HTTP_TOO_MANY_REQUESTS
        );
    }
}
