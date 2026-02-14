<?php

namespace App\Tests\Unit\Application\AuthClient;

use App\Application\AuthClient\PollDeviceFlowHandler;
use App\Application\AuthClient\PollDeviceFlowResult;
use App\Application\AuthClient\Port\AuthClientGateway;
use PHPUnit\Framework\TestCase;

final class PollDeviceFlowHandlerTest extends TestCase
{
    public function testReturnsInvalidDeviceCodeWhenFlowIsUnknown(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('pollDeviceFlow')->with('invalid')->willReturn(null);

        $handler = new PollDeviceFlowHandler($gateway);
        $result = $handler->handle('invalid');

        self::assertSame(PollDeviceFlowResult::STATUS_INVALID_DEVICE_CODE, $result->status());
    }

    public function testReturnsThrottledWhenRetryFieldIsPresent(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('pollDeviceFlow')->with('dc_1')->willReturn([
            'status' => 'PENDING',
            'interval' => 5,
            'retry_in_seconds' => 4,
        ]);

        $handler = new PollDeviceFlowHandler($gateway);
        $result = $handler->handle('dc_1');

        self::assertSame(PollDeviceFlowResult::STATUS_THROTTLED, $result->status());
        self::assertSame(4, $result->payload()['retry_in_seconds'] ?? null);
    }

    public function testReturnsSuccessWithFlowStatusPayload(): void
    {
        $gateway = $this->createMock(AuthClientGateway::class);
        $gateway->expects(self::once())->method('pollDeviceFlow')->with('dc_2')->willReturn([
            'status' => 'APPROVED',
            'client_id' => 'agent-default',
            'client_kind' => 'AGENT',
            'secret_key' => 'secret',
        ]);

        $handler = new PollDeviceFlowHandler($gateway);
        $result = $handler->handle('dc_2');

        self::assertSame(PollDeviceFlowResult::STATUS_SUCCESS, $result->status());
        self::assertSame('APPROVED', $result->payload()['status'] ?? null);
    }
}
