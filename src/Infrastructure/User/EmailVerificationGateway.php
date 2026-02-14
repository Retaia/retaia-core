<?php

namespace App\Infrastructure\User;

use App\Application\Auth\Port\EmailVerificationGateway as EmailVerificationGatewayPort;
use App\User\Service\EmailVerificationService;

final class EmailVerificationGateway implements EmailVerificationGatewayPort
{
    public function __construct(
        private EmailVerificationService $service,
    ) {
    }

    public function requestVerification(string $email): ?string
    {
        return $this->service->requestVerification($email);
    }

    public function confirmVerification(string $token): bool
    {
        return $this->service->confirmVerification($token);
    }

    public function forceVerifyByEmail(string $email, ?string $actorId): bool
    {
        return $this->service->forceVerifyByEmail($email, $actorId);
    }
}
