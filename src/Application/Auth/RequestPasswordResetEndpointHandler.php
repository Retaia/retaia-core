<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\PasswordResetRequestRateLimiterGateway;

final class RequestPasswordResetEndpointHandler
{
    public function __construct(
        private RequestPasswordResetHandler $requestPasswordResetHandler,
        private PasswordResetRequestRateLimiterGateway $rateLimiter,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, string $remoteAddress): RequestPasswordResetEndpointResult
    {
        $email = trim((string) ($payload['email'] ?? ''));
        if ($email === '') {
            return new RequestPasswordResetEndpointResult(RequestPasswordResetEndpointResult::STATUS_VALIDATION_FAILED);
        }

        $retryInSeconds = $this->rateLimiter->retryInSecondsOrNull($email, $remoteAddress);
        if ($retryInSeconds !== null) {
            return new RequestPasswordResetEndpointResult(
                RequestPasswordResetEndpointResult::STATUS_TOO_MANY_ATTEMPTS,
                null,
                $retryInSeconds
            );
        }

        $result = $this->requestPasswordResetHandler->handle($email);

        return new RequestPasswordResetEndpointResult(
            RequestPasswordResetEndpointResult::STATUS_ACCEPTED,
            $result->token()
        );
    }
}
