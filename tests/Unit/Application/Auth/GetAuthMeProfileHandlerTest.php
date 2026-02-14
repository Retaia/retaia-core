<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\GetAuthMeProfileHandler;
use PHPUnit\Framework\TestCase;

final class GetAuthMeProfileHandlerTest extends TestCase
{
    public function testHandleReturnsNormalizedMeProfile(): void
    {
        $handler = new GetAuthMeProfileHandler();
        $result = $handler->handle('u-1', 'user@retaia.local', ['ROLE_ADMIN']);

        self::assertSame('u-1', $result->id());
        self::assertSame('user@retaia.local', $result->email());
        self::assertSame(['ROLE_ADMIN'], $result->roles());
    }
}
