<?php

namespace App\Tests\Unit\Controller;

use App\Auth\UserAccessJwtService;
use App\Auth\UserAccessTokenService;
use App\Auth\UserAuthSessionRepositoryInterface;
use App\Auth\UserAuthSessionService;
use App\Controller\Api\AuthCurrentSessionResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AuthCurrentSessionResolverTest extends TestCase
{
    public function testResolveReturnsNullWithoutBearerToken(): void
    {
        $resolver = new AuthCurrentSessionResolver(
            new UserAccessTokenService(
                new UserAuthSessionService($this->createMock(UserAuthSessionRepositoryInterface::class)),
                new UserAccessJwtService('test-secret', 3600),
            )
        );

        self::assertNull($resolver->resolve(Request::create('/api/v1/auth/me', 'GET')));
    }
}
