<?php

namespace App\Application\Auth\Port;

interface PasswordResetRequestRateLimiterGateway
{
    public function retryInSecondsOrNull(string $email, string $remoteAddress): ?int;
}
