<?php

namespace App\Application\Auth;

final class VerifyEmailEndpointsHandler
{
    public function __construct(
        private ConfirmEmailVerificationHandler $confirmEmailVerificationHandler,
        private AdminConfirmEmailVerificationHandler $adminConfirmEmailVerificationHandler,
        private ResolveAdminActorHandler $resolveAdminActorHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function confirm(array $payload): ConfirmEmailVerificationEndpointResult
    {
        $token = trim((string) ($payload['token'] ?? ''));
        if ($token === '') {
            return new ConfirmEmailVerificationEndpointResult(ConfirmEmailVerificationEndpointResult::STATUS_VALIDATION_FAILED);
        }

        $result = $this->confirmEmailVerificationHandler->handle($token);
        if ($result->status() === ConfirmEmailVerificationResult::STATUS_INVALID_TOKEN) {
            return new ConfirmEmailVerificationEndpointResult(ConfirmEmailVerificationEndpointResult::STATUS_INVALID_TOKEN);
        }

        return new ConfirmEmailVerificationEndpointResult(ConfirmEmailVerificationEndpointResult::STATUS_VERIFIED);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function adminConfirm(array $payload): AdminConfirmEmailVerificationEndpointResult
    {
        $adminActor = $this->resolveAdminActorHandler->handle();
        if ($adminActor->status() === ResolveAdminActorResult::STATUS_FORBIDDEN_ACTOR) {
            return new AdminConfirmEmailVerificationEndpointResult(AdminConfirmEmailVerificationEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        $email = trim((string) ($payload['email'] ?? ''));
        if ($email === '') {
            return new AdminConfirmEmailVerificationEndpointResult(AdminConfirmEmailVerificationEndpointResult::STATUS_VALIDATION_FAILED);
        }

        $result = $this->adminConfirmEmailVerificationHandler->handle($email, $adminActor->actorId());
        if ($result->status() === AdminConfirmEmailVerificationResult::STATUS_USER_NOT_FOUND) {
            return new AdminConfirmEmailVerificationEndpointResult(AdminConfirmEmailVerificationEndpointResult::STATUS_USER_NOT_FOUND);
        }

        return new AdminConfirmEmailVerificationEndpointResult(AdminConfirmEmailVerificationEndpointResult::STATUS_VERIFIED);
    }
}
