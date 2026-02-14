<?php

namespace App\Application\Auth\Port;

interface PasswordResetGateway
{
    public function requestReset(string $email): ?string;

    public function resetPassword(string $token, string $newPassword): bool;
}
