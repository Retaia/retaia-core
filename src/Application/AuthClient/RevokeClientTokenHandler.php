<?php

namespace App\Application\AuthClient;

use App\Application\AuthClient\Port\AuthClientGateway;
use App\Domain\AuthClient\TechnicalClientAdminPolicy;

final class RevokeClientTokenHandler
{
    public function __construct(
        private TechnicalClientAdminPolicy $policy,
        private AuthClientGateway $authClientGateway,
    ) {
    }

    public function handle(string $clientId): RevokeClientTokenResult
    {
        if (!$this->authClientGateway->hasClient($clientId)) {
            return new RevokeClientTokenResult(RevokeClientTokenResult::STATUS_VALIDATION_FAILED);
        }

        $clientKind = $this->authClientGateway->clientKind($clientId);
        if ($this->policy->isRevokeForbiddenScope($clientKind)) {
            return new RevokeClientTokenResult(RevokeClientTokenResult::STATUS_FORBIDDEN_SCOPE, $clientKind);
        }

        if (!$this->authClientGateway->revokeToken($clientId)) {
            return new RevokeClientTokenResult(RevokeClientTokenResult::STATUS_VALIDATION_FAILED);
        }

        return new RevokeClientTokenResult(RevokeClientTokenResult::STATUS_SUCCESS, $clientKind);
    }
}
