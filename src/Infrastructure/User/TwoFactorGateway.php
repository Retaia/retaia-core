<?php

namespace App\Infrastructure\User;

use App\Application\Auth\Port\TwoFactorGateway as TwoFactorGatewayPort;
use App\User\Service\TwoFactorService;

final class TwoFactorGateway implements TwoFactorGatewayPort
{
    public function __construct(
        private TwoFactorService $service,
    ) {
    }

    public function setup(string $userId, string $email): array
    {
        return $this->service->setup($userId, $email);
    }

    public function enable(string $userId, string $otpCode): bool
    {
        return $this->service->enable($userId, $otpCode);
    }

    public function disable(string $userId, string $otpCode): bool
    {
        return $this->service->disable($userId, $otpCode);
    }
}
