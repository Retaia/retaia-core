<?php

namespace App\Application\Auth\Port;

interface TwoFactorGateway
{
    /**
     * @return array{secret: string, otpauth_uri: string}
     */
    public function setup(string $userId, string $email): array;

    public function enable(string $userId, string $otpCode): bool;

    public function disable(string $userId, string $otpCode): bool;
}
