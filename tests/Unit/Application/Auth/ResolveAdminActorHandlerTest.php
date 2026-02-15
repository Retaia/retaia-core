<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\Port\AdminActorGateway;
use App\Application\Auth\ResolveAdminActorHandler;
use App\Application\Auth\ResolveAdminActorResult;
use PHPUnit\Framework\TestCase;

final class ResolveAdminActorHandlerTest extends TestCase
{
    public function testHandleReturnsForbiddenForNonAdmin(): void
    {
        $gateway = $this->createMock(AdminActorGateway::class);
        $gateway->expects(self::once())->method('isAdmin')->willReturn(false);
        $gateway->expects(self::never())->method('actorId');

        $handler = new ResolveAdminActorHandler($gateway);
        $result = $handler->handle();

        self::assertSame(ResolveAdminActorResult::STATUS_FORBIDDEN_ACTOR, $result->status());
        self::assertNull($result->actorId());
    }

    public function testHandleReturnsAuthorizedWithActorIdForAdmin(): void
    {
        $gateway = $this->createMock(AdminActorGateway::class);
        $gateway->expects(self::once())->method('isAdmin')->willReturn(true);
        $gateway->expects(self::once())->method('actorId')->willReturn('admin-1');

        $handler = new ResolveAdminActorHandler($gateway);
        $result = $handler->handle();

        self::assertSame(ResolveAdminActorResult::STATUS_AUTHORIZED, $result->status());
        self::assertSame('admin-1', $result->actorId());
    }
}
