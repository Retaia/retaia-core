<?php

namespace App\Infrastructure\Auth;

use App\Application\AuthClient\Port\DeviceApprovalSecondFactorGateway as DeviceApprovalSecondFactorGatewayPort;
use App\User\Service\TwoFactorService;

final class DeviceApprovalSecondFactorGateway implements DeviceApprovalSecondFactorGatewayPort
{
    public function __construct(
        private TwoFactorService $twoFactorService,
    ) {
    }

    public function isEnabled(string $userId): bool
    {
        return $this->twoFactorService->isEnabled($userId);
    }

    public function verifyLoginOtp(string $userId, string $otpCode): bool
    {
        return $this->twoFactorService->verifyLoginOtp($userId, $otpCode);
    }
}
