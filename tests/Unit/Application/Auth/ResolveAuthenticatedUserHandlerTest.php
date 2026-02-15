<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\ResolveAuthenticatedUserResult;
use PHPUnit\Framework\TestCase;

final class ResolveAuthenticatedUserHandlerTest extends TestCase
{
    public function testHandleReturnsUnauthorizedWhenNoCurrentUser(): void
    {
        $gateway = $this->createMock(AuthenticatedUserGateway::class);
        $gateway->expects(self::once())->method('currentUser')->willReturn(null);

        $handler = new ResolveAuthenticatedUserHandler($gateway);
        $result = $handler->handle();

        self::assertSame(ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED, $result->status());
        self::assertNull($result->id());
    }

    public function testHandleReturnsAuthenticatedUserProfileWhenPresent(): void
    {
        $gateway = $this->createMock(AuthenticatedUserGateway::class);
        $gateway->expects(self::once())->method('currentUser')->willReturn([
            'id' => 'u-1',
            'email' => 'user@retaia.local',
            'roles' => ['ROLE_ADMIN'],
        ]);

        $handler = new ResolveAuthenticatedUserHandler($gateway);
        $result = $handler->handle();

        self::assertSame(ResolveAuthenticatedUserResult::STATUS_AUTHENTICATED, $result->status());
        self::assertSame('u-1', $result->id());
        self::assertSame('user@retaia.local', $result->email());
        self::assertSame(['ROLE_ADMIN'], $result->roles());
    }
}
