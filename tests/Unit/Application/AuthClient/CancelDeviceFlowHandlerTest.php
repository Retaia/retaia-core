<?php

namespace App\Tests\Unit\Application\AuthClient;

use App\Application\AuthClient\CancelDeviceFlowHandler;
use App\Application\AuthClient\CancelDeviceFlowResult;
use App\Application\AuthClient\Port\AuthClientGateway;
use PHPUnit\Framework\TestCase;

final class CancelDeviceFlowHandlerTest extends TestCase
{
    public function testReturnsInvalidDeviceCodeWhenUnknown(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('cancelDeviceFlow')->with('invalid')->willReturn(null);

        $handler = new CancelDeviceFlowHandler($gateway);
        $result = $handler->handle('invalid');

        self::assertSame(CancelDeviceFlowResult::STATUS_INVALID_DEVICE_CODE, $result->status());
    }

    public function testReturnsExpiredDeviceCodeWhenFlowExpired(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('cancelDeviceFlow')->with('expired')->willReturn([
            'status' => 'EXPIRED',
        ]);

        $handler = new CancelDeviceFlowHandler($gateway);
        $result = $handler->handle('expired');

        self::assertSame(CancelDeviceFlowResult::STATUS_EXPIRED_DEVICE_CODE, $result->status());
    }

    public function testReturnsSuccessWhenFlowIsCanceled(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('cancelDeviceFlow')->with('dc_1')->willReturn([
            'status' => 'DENIED',
        ]);

        $handler = new CancelDeviceFlowHandler($gateway);
        $result = $handler->handle('dc_1');

        self::assertSame(CancelDeviceFlowResult::STATUS_SUCCESS, $result->status());
    }
}
