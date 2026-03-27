<?php

namespace App\Application\Auth\Port;

interface TwoFactorGateway
{
    /**
     * @return array{method: string, issuer: string, account_name: string, secret: string, otpauth_uri: string, qr_svg?: string}
     */
    public function setup(string $userId, string $email): array;

    public function enable(string $userId, string $otpCode): bool;

    public function disable(string $userId, string $otpCode): bool;

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(string $userId): array;
}
