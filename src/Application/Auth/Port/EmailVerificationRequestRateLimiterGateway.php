<?php

namespace App\Application\Auth\Port;

interface EmailVerificationRequestRateLimiterGateway
{
    public function retryInSecondsOrNull(string $email, string $remoteAddress): ?int;
}
