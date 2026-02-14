<?php

namespace App\Application\Auth\Port;

interface EmailVerificationGateway
{
    public function requestVerification(string $email): ?string;

    public function confirmVerification(string $token): bool;

    public function forceVerifyByEmail(string $email, ?string $actorId): bool;
}
