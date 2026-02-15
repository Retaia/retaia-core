<?php

namespace App\Application\AuthClient;

use App\Application\Auth\ResolveAdminActorHandler;
use App\Application\Auth\ResolveAdminActorResult;
use App\Application\Auth\ResolveAuthenticatedUserResult;
use App\Application\Auth\ResolveAuthenticatedUserUseCase;

final class AuthClientAdminEndpointsHandler
{
    public function __construct(
        private ResolveAuthenticatedUserUseCase $resolveAuthenticatedUserHandler,
        private ResolveAdminActorHandler $resolveAdminActorHandler,
        private RevokeClientTokenHandler $revokeClientTokenHandler,
        private RotateClientSecretHandler $rotateClientSecretHandler,
    ) {
    }

    public function revoke(string $clientId): RevokeClientTokenEndpointResult
    {
        if ($this->resolveAuthenticatedUserHandler->handle()->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new RevokeClientTokenEndpointResult(RevokeClientTokenEndpointResult::STATUS_UNAUTHORIZED);
        }

        if ($this->resolveAdminActorHandler->handle()->status() === ResolveAdminActorResult::STATUS_FORBIDDEN_ACTOR) {
            return new RevokeClientTokenEndpointResult(RevokeClientTokenEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        $result = $this->revokeClientTokenHandler->handle($clientId);
        if ($result->status() === RevokeClientTokenResult::STATUS_VALIDATION_FAILED) {
            return new RevokeClientTokenEndpointResult(RevokeClientTokenEndpointResult::STATUS_VALIDATION_FAILED);
        }

        if ($result->status() === RevokeClientTokenResult::STATUS_FORBIDDEN_SCOPE) {
            return new RevokeClientTokenEndpointResult(
                RevokeClientTokenEndpointResult::STATUS_FORBIDDEN_SCOPE,
                $result->clientKind()
            );
        }

        return new RevokeClientTokenEndpointResult(
            RevokeClientTokenEndpointResult::STATUS_SUCCESS,
            $result->clientKind()
        );
    }

    public function rotate(string $clientId): RotateClientSecretEndpointResult
    {
        if ($this->resolveAuthenticatedUserHandler->handle()->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new RotateClientSecretEndpointResult(RotateClientSecretEndpointResult::STATUS_UNAUTHORIZED);
        }

        if ($this->resolveAdminActorHandler->handle()->status() === ResolveAdminActorResult::STATUS_FORBIDDEN_ACTOR) {
            return new RotateClientSecretEndpointResult(RotateClientSecretEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        $result = $this->rotateClientSecretHandler->handle($clientId);
        if ($result->status() === RotateClientSecretResult::STATUS_VALIDATION_FAILED) {
            return new RotateClientSecretEndpointResult(RotateClientSecretEndpointResult::STATUS_VALIDATION_FAILED);
        }

        return new RotateClientSecretEndpointResult(
            RotateClientSecretEndpointResult::STATUS_SUCCESS,
            $result->secretKey(),
            $result->clientKind()
        );
    }
}
