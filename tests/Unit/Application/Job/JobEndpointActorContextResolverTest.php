<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\ResolveAuthenticatedUserResult;
use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Job\JobEndpointActorContextResolver;
use PHPUnit\Framework\TestCase;

final class JobEndpointActorContextResolverTest extends TestCase
{
    public function testReturnsAnonymousContextWhenUnauthorized(): void
    {
        $resolver = new JobEndpointActorContextResolver($this->resolveHandler(
            new ResolveAuthenticatedUserResult(ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED)
        ));

        self::assertSame('anonymous', $resolver->actorId());
        self::assertSame([], $resolver->actorRoles());
    }

    public function testReturnsAuthenticatedContext(): void
    {
        $resolver = new JobEndpointActorContextResolver($this->resolveHandler(
            new ResolveAuthenticatedUserResult(ResolveAuthenticatedUserResult::STATUS_AUTHENTICATED, 'agent-1', 'a@b.c', ['ROLE_AGENT'])
        ));

        self::assertSame('agent-1', $resolver->actorId());
        self::assertSame(['ROLE_AGENT'], $resolver->actorRoles());
    }

    private function resolveHandler(ResolveAuthenticatedUserResult $result): ResolveAuthenticatedUserHandler
    {
        $gateway = $this->createMock(AuthenticatedUserGateway::class);
        if ($result->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            $gateway->method('currentUser')->willReturn(null);
        } else {
            $gateway->method('currentUser')->willReturn([
                'id' => $result->id(),
                'email' => $result->email(),
                'roles' => $result->roles(),
            ]);
        }

        return new ResolveAuthenticatedUserHandler($gateway);
    }
}
