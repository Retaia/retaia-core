<?php

namespace App\Application\Auth;

final class SetupTwoFactorResult
{
    public const STATUS_READY = 'READY';
    public const STATUS_ALREADY_ENABLED = 'ALREADY_ENABLED';

    /**
     * @param array{secret: string, otpauth_uri: string}|null $setup
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
     * @return array{secret: string, otpauth_uri: string}|null
     */
    public function setup(): ?array
    {
        return $this->setup;
    }
}
