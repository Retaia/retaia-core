<?php

namespace App\Tests\Unit\Application\AuthClient;

use App\Application\AuthClient\Port\AuthClientGateway;
use App\Application\AuthClient\RotateClientSecretHandler;
use App\Application\AuthClient\RotateClientSecretResult;
use PHPUnit\Framework\TestCase;

final class RotateClientSecretHandlerTest extends TestCase
{
    public function testReturnsValidationFailedWhenClientIsUnknown(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('rotateSecret')->with('unknown')->willReturn(null);

        $handler = new RotateClientSecretHandler($gateway);
        $result = $handler->handle('unknown');

        self::assertSame(RotateClientSecretResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testReturnsSuccessWithNewSecretAndClientKind(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('rotateSecret')->with('agent-default')->willReturn('new-secret');
        $gateway->expects(self::once())->method('clientKind')->with('agent-default')->willReturn('AGENT');

        $handler = new RotateClientSecretHandler($gateway);
        $result = $handler->handle('agent-default');

        self::assertSame(RotateClientSecretResult::STATUS_SUCCESS, $result->status());
        self::assertSame('new-secret', $result->secretKey());
        self::assertSame('AGENT', $result->clientKind());
    }
}
