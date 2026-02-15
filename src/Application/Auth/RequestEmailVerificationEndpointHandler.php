<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\EmailVerificationRequestRateLimiterGateway;

final class RequestEmailVerificationEndpointHandler
{
    public function __construct(
        private RequestEmailVerificationHandler $requestEmailVerificationHandler,
        private EmailVerificationRequestRateLimiterGateway $rateLimiter,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, string $remoteAddress): RequestEmailVerificationEndpointResult
    {
        $email = trim((string) ($payload['email'] ?? ''));
        if ($email === '') {
            return new RequestEmailVerificationEndpointResult(RequestEmailVerificationEndpointResult::STATUS_VALIDATION_FAILED);
        }

        $retryInSeconds = $this->rateLimiter->retryInSecondsOrNull($email, $remoteAddress);
        if ($retryInSeconds !== null) {
            return new RequestEmailVerificationEndpointResult(
                RequestEmailVerificationEndpointResult::STATUS_TOO_MANY_ATTEMPTS,
                null,
                $retryInSeconds
            );
        }

        $result = $this->requestEmailVerificationHandler->handle($email);

        return new RequestEmailVerificationEndpointResult(
            RequestEmailVerificationEndpointResult::STATUS_ACCEPTED,
            $result->token()
        );
    }
}
