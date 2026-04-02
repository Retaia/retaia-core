<?php

namespace App\Security;

use App\Controller\Api\ApiErrorResponseFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApiLoginSecondFactorAttemptLimiter
{
    private ?RateLimit $lastLimit = null;

    public function __construct(
        #[Autowire(service: 'limiter.auth_2fa_challenge')]
        private RateLimiterFactory $twoFactorChallengeRateLimiter,
        private TranslatorInterface $translator,
    ) {
    }

    public function consume(string $userId, string $remoteAddress): bool
    {
        $this->lastLimit = $this->twoFactorChallengeRateLimiter
            ->create(hash('sha256', $userId.'|'.$remoteAddress.'|login'))
            ->consume(1);

        return $this->lastLimit->isAccepted();
    }

    public function tooManyAttemptsResponse(): JsonResponse
    {
        $response = ApiErrorResponseFactory::create(
            'TOO_MANY_ATTEMPTS',
            $this->translator->trans('auth.error.too_many_2fa_attempts'),
            Response::HTTP_TOO_MANY_REQUESTS
        );

        $retryAfter = $this->retryAfterSeconds();
        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    private function retryAfterSeconds(): ?int
    {
        $retryAt = $this->lastLimit?->getRetryAfter();
        if (!$retryAt instanceof \DateTimeInterface) {
            return null;
        }

        return max(0, $retryAt->getTimestamp() - time());
    }
}
