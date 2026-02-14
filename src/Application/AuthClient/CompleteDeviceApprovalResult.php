<?php

namespace App\Application\AuthClient;

final class CompleteDeviceApprovalResult
{
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_VALIDATION_FAILED_OTP_REQUIRED = 'VALIDATION_FAILED_OTP_REQUIRED';
    public const STATUS_INVALID_2FA_CODE = 'INVALID_2FA_CODE';
    public const STATUS_INVALID_DEVICE_CODE = 'INVALID_DEVICE_CODE';
    public const STATUS_EXPIRED_DEVICE_CODE = 'EXPIRED_DEVICE_CODE';
    public const STATUS_STATE_CONFLICT = 'STATE_CONFLICT';

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
