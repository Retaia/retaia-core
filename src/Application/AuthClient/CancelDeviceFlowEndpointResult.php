<?php

namespace App\Application\AuthClient;

final class CancelDeviceFlowEndpointResult
{
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_INVALID_DEVICE_CODE = 'INVALID_DEVICE_CODE';
    public const STATUS_EXPIRED_DEVICE_CODE = 'EXPIRED_DEVICE_CODE';
    public const STATUS_SUCCESS = 'SUCCESS';

    public function __construct(
        private string $status,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }
}
