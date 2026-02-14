<?php

namespace App\Application\AuthClient;

final class StartDeviceFlowResult
{
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FORBIDDEN_ACTOR = 'FORBIDDEN_ACTOR';
    public const STATUS_FORBIDDEN_SCOPE = 'FORBIDDEN_SCOPE';

    /**
     * @param array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string, expires_in: int, interval: int}|null $payload
     */
    public function __construct(
        private string $status,
        private ?array $payload = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string, expires_in: int, interval: int}|null
     */
    public function payload(): ?array
    {
        return $this->payload;
    }
}
