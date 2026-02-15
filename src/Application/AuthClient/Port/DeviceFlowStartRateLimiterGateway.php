<?php

namespace App\Application\AuthClient\Port;

interface DeviceFlowStartRateLimiterGateway
{
    public function retryInSecondsOrNull(string $clientKind, string $remoteAddress): ?int;
}

