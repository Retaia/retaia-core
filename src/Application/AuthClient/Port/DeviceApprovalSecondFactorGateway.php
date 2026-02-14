<?php

namespace App\Application\AuthClient\Port;

interface DeviceApprovalSecondFactorGateway
{
    public function isEnabled(string $userId): bool;

    public function verifyLoginOtp(string $userId, string $otpCode): bool;
}
