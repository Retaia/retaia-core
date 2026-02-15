<?php

namespace App\Application\AuthClient;

final class PollDeviceFlowEndpointResult
{
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_INVALID_DEVICE_CODE = 'INVALID_DEVICE_CODE';
    public const STATUS_THROTTLED = 'THROTTLED';
    public const STATUS_SUCCESS = 'SUCCESS';

    /**
     * @param array{status: string, client_id?: string, client_kind?: string, secret_key?: string, interval?: int, retry_in_seconds?: int}|null $payload
     */
    public function __construct(
        private string $status,
        private ?array $payload = null,
        private ?int $retryInSeconds = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array{status: string, client_id?: string, client_kind?: string, secret_key?: string, interval?: int, retry_in_seconds?: int}|null
     */
    public function payload(): ?array
    {
        return $this->payload;
    }

    public function retryInSeconds(): ?int
    {
        return $this->retryInSeconds;
    }
}
