<?php

namespace App\Application\AuthClient;

use App\Application\AuthClient\Port\AuthClientGateway;

final class RotateClientSecretHandler
{
    public function __construct(
        private AuthClientGateway $authClientGateway,
    ) {
    }

    public function handle(string $clientId): RotateClientSecretResult
    {
        $secretKey = $this->authClientGateway->rotateSecret($clientId);
        if (!is_string($secretKey)) {
            return new RotateClientSecretResult(RotateClientSecretResult::STATUS_VALIDATION_FAILED);
        }

        return new RotateClientSecretResult(
            RotateClientSecretResult::STATUS_SUCCESS,
            $secretKey,
            $this->authClientGateway->clientKind($clientId)
        );
    }
}
