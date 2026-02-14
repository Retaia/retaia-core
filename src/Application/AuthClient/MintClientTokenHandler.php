<?php

namespace App\Application\AuthClient;

use App\Application\AuthClient\Port\AuthClientGateway;
use App\Domain\AuthClient\TechnicalClientTokenPolicy;

final class MintClientTokenHandler
{
    public function __construct(
        private TechnicalClientTokenPolicy $policy,
        private AuthClientGateway $authClientGateway,
    ) {
    }

    public function handle(string $clientId, string $clientKind, string $secretKey): MintClientTokenResult
    {
        if ($this->policy->isForbiddenActor($clientKind)) {
            return new MintClientTokenResult(MintClientTokenResult::STATUS_FORBIDDEN_ACTOR);
        }

        if ($this->policy->isForbiddenScope($clientKind, $this->authClientGateway->isMcpDisabledByAppPolicy())) {
            return new MintClientTokenResult(MintClientTokenResult::STATUS_FORBIDDEN_SCOPE);
        }

        $token = $this->authClientGateway->mintToken($clientId, $clientKind, $secretKey);
        if (!is_array($token)) {
            return new MintClientTokenResult(MintClientTokenResult::STATUS_UNAUTHORIZED);
        }

        return new MintClientTokenResult(MintClientTokenResult::STATUS_SUCCESS, $token);
    }
}
