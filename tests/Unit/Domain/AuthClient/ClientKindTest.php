<?php

namespace App\Tests\Unit\Domain\AuthClient;

use App\Domain\AuthClient\ClientKind;
use PHPUnit\Framework\TestCase;

final class ClientKindTest extends TestCase
{
    public function testInteractiveKinds(): void
    {
        self::assertSame(
            [ClientKind::UI_WEB, ClientKind::UI_MOBILE, ClientKind::AGENT],
            ClientKind::interactive()
        );
    }

    public function testTechnicalKinds(): void
    {
        self::assertSame(
            [ClientKind::AGENT, ClientKind::MCP],
            ClientKind::technical()
        );
    }

    public function testMembershipHelpers(): void
    {
        self::assertTrue(ClientKind::isInteractive(ClientKind::UI_WEB));
        self::assertTrue(ClientKind::isInteractive(ClientKind::UI_MOBILE));
        self::assertTrue(ClientKind::isInteractive(ClientKind::AGENT));
        self::assertFalse(ClientKind::isInteractive(ClientKind::MCP));

        self::assertTrue(ClientKind::isTechnical(ClientKind::AGENT));
        self::assertTrue(ClientKind::isTechnical(ClientKind::MCP));
        self::assertFalse(ClientKind::isTechnical(ClientKind::UI_WEB));
    }
}
