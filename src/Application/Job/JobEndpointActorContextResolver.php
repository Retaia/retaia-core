<?php

namespace App\Application\Job;

use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\ResolveAuthenticatedUserResult;

final class JobEndpointActorContextResolver
{
    public function __construct(
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
    ) {
    }

    public function actorId(): string
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return 'anonymous';
        }

        return (string) $authenticatedUser->id();
    }

    /**
     * @return array<int, string>
     */
    public function actorRoles(): array
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return [];
        }

        return $authenticatedUser->roles();
    }
}
