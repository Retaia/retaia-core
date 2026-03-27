<?php

namespace App\Application\Auth;

final class TwoFactorSetupEndpointResult
{
    public const STATUS_UNAUTHORIZED = 'UNAUTHORIZED';
    public const STATUS_ALREADY_ENABLED = 'ALREADY_ENABLED';
    public const STATUS_READY = 'READY';

    /**
     * @param array{method: string, issuer: string, account_name: string, secret: string, otpauth_uri: string, qr_svg?: string}|null $setup
     */
    public function __construct(
        private string $status,
        private ?array $setup = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array{method: string, issuer: string, account_name: string, secret: string, otpauth_uri: string, qr_svg?: string}|null
     */
    public function setup(): ?array
    {
        return $this->setup;
    }
}
