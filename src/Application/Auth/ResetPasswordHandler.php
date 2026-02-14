<?php

namespace App\Application\Auth;

use App\Application\Auth\Port\PasswordPolicyGateway;
use App\Application\Auth\Port\PasswordResetGateway;

final class ResetPasswordHandler
{
    public function __construct(
        private PasswordPolicyGateway $policyGateway,
        private PasswordResetGateway $resetGateway,
    ) {
    }

    public function handle(string $token, string $newPassword): ResetPasswordResult
    {
        $violations = $this->policyGateway->violations($newPassword);
        if ($violations !== []) {
            return new ResetPasswordResult(ResetPasswordResult::STATUS_VALIDATION_FAILED, $violations);
        }

        if (!$this->resetGateway->resetPassword($token, $newPassword)) {
            return new ResetPasswordResult(ResetPasswordResult::STATUS_INVALID_TOKEN);
        }

        return new ResetPasswordResult(ResetPasswordResult::STATUS_PASSWORD_RESET);
    }
}
