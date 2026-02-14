<?php

namespace App\Tests\Unit\Application\AuthClient;

use App\Application\AuthClient\ApproveDeviceFlowHandler;
use App\Application\AuthClient\ApproveDeviceFlowResult;
use App\Application\AuthClient\Port\AuthClientGateway;
use PHPUnit\Framework\TestCase;

final class ApproveDeviceFlowHandlerTest extends TestCase
{
    public function testReturnsInvalidDeviceCodeWhenUnknownUserCode(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('approveDeviceFlow')->with('UNKNOWN')->willReturn(null);

        $handler = new ApproveDeviceFlowHandler($gateway);
        $result = $handler->handle('UNKNOWN');

        self::assertSame(ApproveDeviceFlowResult::STATUS_INVALID_DEVICE_CODE, $result->status());
    }

    public function testReturnsExpiredDeviceCodeWhenFlowExpired(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('approveDeviceFlow')->with('EXPIRED01')->willReturn([
            'status' => 'EXPIRED',
        ]);

        $handler = new ApproveDeviceFlowHandler($gateway);
        $result = $handler->handle('EXPIRED01');

        self::assertSame(ApproveDeviceFlowResult::STATUS_EXPIRED_DEVICE_CODE, $result->status());
    }

    public function testReturnsStateConflictWhenFlowIsNotApprovable(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('approveDeviceFlow')->with('DENIED01')->willReturn([
            'status' => 'DENIED',
        ]);

        $handler = new ApproveDeviceFlowHandler($gateway);
        $result = $handler->handle('DENIED01');

        self::assertSame(ApproveDeviceFlowResult::STATUS_STATE_CONFLICT, $result->status());
    }

    public function testReturnsSuccessWhenFlowIsApproved(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('approveDeviceFlow')->with('APPROVED1')->willReturn([
            'status' => 'APPROVED',
        ]);

        $handler = new ApproveDeviceFlowHandler($gateway);
        $result = $handler->handle('APPROVED1');

        self::assertSame(ApproveDeviceFlowResult::STATUS_SUCCESS, $result->status());
    }
}
