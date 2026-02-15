<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\AdminConfirmEmailVerificationEndpointResult;
use App\Application\Auth\AdminConfirmEmailVerificationHandler;
use App\Application\Auth\ConfirmEmailVerificationEndpointResult;
use App\Application\Auth\ConfirmEmailVerificationHandler;
use App\Application\Auth\Port\AdminActorGateway;
use App\Application\Auth\Port\EmailVerificationGateway;
use App\Application\Auth\ResolveAdminActorHandler;
use App\Application\Auth\VerifyEmailEndpointsHandler;
use PHPUnit\Framework\TestCase;

final class VerifyEmailEndpointsHandlerTest extends TestCase
{
    public function testConfirmReturnsValidationFailedWhenTokenMissing(): void
    {
        $emailGateway = $this->createMock(EmailVerificationGateway::class);
        $emailGateway->expects(self::never())->method('confirmVerification');

        $adminGateway = $this->createMock(AdminActorGateway::class);

        $handler = new VerifyEmailEndpointsHandler(
            new ConfirmEmailVerificationHandler($emailGateway),
            new AdminConfirmEmailVerificationHandler($emailGateway),
            new ResolveAdminActorHandler($adminGateway),
        );

        $result = $handler->confirm([]);

        self::assertSame(ConfirmEmailVerificationEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testConfirmReturnsInvalidTokenWhenUseCaseRejectsToken(): void
    {
        $emailGateway = $this->createMock(EmailVerificationGateway::class);
        $emailGateway->expects(self::once())->method('confirmVerification')->with('bad')->willReturn(false);

        $adminGateway = $this->createMock(AdminActorGateway::class);

        $handler = new VerifyEmailEndpointsHandler(
            new ConfirmEmailVerificationHandler($emailGateway),
            new AdminConfirmEmailVerificationHandler($emailGateway),
            new ResolveAdminActorHandler($adminGateway),
        );

        $result = $handler->confirm(['token' => 'bad']);

        self::assertSame(ConfirmEmailVerificationEndpointResult::STATUS_INVALID_TOKEN, $result->status());
    }

    public function testAdminConfirmReturnsForbiddenActorWhenActorIsNotAdmin(): void
    {
        $emailGateway = $this->createMock(EmailVerificationGateway::class);
        $emailGateway->expects(self::never())->method('forceVerifyByEmail');

        $adminGateway = $this->createMock(AdminActorGateway::class);
        $adminGateway->expects(self::once())->method('isAdmin')->willReturn(false);

        $handler = new VerifyEmailEndpointsHandler(
            new ConfirmEmailVerificationHandler($emailGateway),
            new AdminConfirmEmailVerificationHandler($emailGateway),
            new ResolveAdminActorHandler($adminGateway),
        );

        $result = $handler->adminConfirm(['email' => 'admin@retaia.local']);

        self::assertSame(AdminConfirmEmailVerificationEndpointResult::STATUS_FORBIDDEN_ACTOR, $result->status());
    }

    public function testAdminConfirmReturnsUserNotFoundWhenUseCaseRejectsEmail(): void
    {
        $emailGateway = $this->createMock(EmailVerificationGateway::class);
        $emailGateway->expects(self::once())->method('forceVerifyByEmail')->with('missing@retaia.local', 'a1')->willReturn(false);

        $adminGateway = $this->createMock(AdminActorGateway::class);
        $adminGateway->expects(self::once())->method('isAdmin')->willReturn(true);
        $adminGateway->expects(self::once())->method('actorId')->willReturn('a1');

        $handler = new VerifyEmailEndpointsHandler(
            new ConfirmEmailVerificationHandler($emailGateway),
            new AdminConfirmEmailVerificationHandler($emailGateway),
            new ResolveAdminActorHandler($adminGateway),
        );

        $result = $handler->adminConfirm(['email' => 'missing@retaia.local']);

        self::assertSame(AdminConfirmEmailVerificationEndpointResult::STATUS_USER_NOT_FOUND, $result->status());
    }
}
