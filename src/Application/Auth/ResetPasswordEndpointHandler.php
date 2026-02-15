<?php

namespace App\Application\Auth;

final class ResetPasswordEndpointHandler
{
    public function __construct(
        private ResetPasswordHandler $resetPasswordHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): ResetPasswordEndpointResult
    {
        $token = trim((string) ($payload['token'] ?? ''));
        $newPassword = (string) ($payload['new_password'] ?? '');

        if ($token === '' || $newPassword === '') {
            return new ResetPasswordEndpointResult(ResetPasswordEndpointResult::STATUS_VALIDATION_FAILED);
        }

        $result = $this->resetPasswordHandler->handle($token, $newPassword);
        if ($result->status() === ResetPasswordResult::STATUS_VALIDATION_FAILED) {
            return new ResetPasswordEndpointResult(
                ResetPasswordEndpointResult::STATUS_VALIDATION_FAILED,
                $result->violations()
            );
        }

        if ($result->status() === ResetPasswordResult::STATUS_INVALID_TOKEN) {
            return new ResetPasswordEndpointResult(ResetPasswordEndpointResult::STATUS_INVALID_TOKEN);
        }

        return new ResetPasswordEndpointResult(ResetPasswordEndpointResult::STATUS_PASSWORD_RESET);
    }
}
