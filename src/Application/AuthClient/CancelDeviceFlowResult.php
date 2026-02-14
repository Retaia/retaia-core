<?php

namespace App\Application\AuthClient;

final class CancelDeviceFlowResult
{
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_INVALID_DEVICE_CODE = 'INVALID_DEVICE_CODE';
    public const STATUS_EXPIRED_DEVICE_CODE = 'EXPIRED_DEVICE_CODE';

    public function __construct(
        private string $status,
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
}
