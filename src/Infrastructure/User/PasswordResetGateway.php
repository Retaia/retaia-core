<?php

namespace App\Infrastructure\User;

use App\Application\Auth\Port\PasswordResetGateway as PasswordResetGatewayPort;
use App\User\Service\PasswordResetService;

final class PasswordResetGateway implements PasswordResetGatewayPort
{
    public function __construct(
        private PasswordResetService $service,
    ) {
    }

    public function requestReset(string $email): ?string
    {
        return $this->service->requestReset($email);
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        return $this->service->resetPassword($token, $newPassword);
    }
}
