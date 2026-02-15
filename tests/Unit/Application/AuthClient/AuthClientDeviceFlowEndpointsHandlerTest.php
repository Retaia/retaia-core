<?php

namespace App\Tests\Unit\Application\AuthClient;

use App\Application\AuthClient\AuthClientDeviceFlowEndpointsHandler;
use App\Application\AuthClient\CancelDeviceFlowEndpointResult;
use App\Application\AuthClient\CancelDeviceFlowHandler;
use App\Application\AuthClient\PollDeviceFlowEndpointResult;
use App\Application\AuthClient\PollDeviceFlowHandler;
use App\Application\AuthClient\Port\DeviceFlowGateway;
use App\Application\AuthClient\StartDeviceFlowEndpointResult;
use App\Application\AuthClient\StartDeviceFlowHandler;
use App\Domain\AuthClient\TechnicalClientTokenPolicy;
use PHPUnit\Framework\TestCase;

final class AuthClientDeviceFlowEndpointsHandlerTest extends TestCase
{
    public function testStartReturnsValidationFailedWhenClientKindMissing(): void
    {
        $gateway = $this->createMock(DeviceFlowGateway::class);
        $gateway->expects(self::never())->method('startDeviceFlow');

        $handler = new AuthClientDeviceFlowEndpointsHandler(
            new StartDeviceFlowHandler(new TechnicalClientTokenPolicy(), $gateway),
            new PollDeviceFlowHandler($gateway),
            new CancelDeviceFlowHandler($gateway),
        );

        $result = $handler->start([]);

        self::assertSame(StartDeviceFlowEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testStartReturnsForbiddenScopeWhenMcpPolicyDisabled(): void
    {
        $gateway = $this->createMock(DeviceFlowGateway::class);
        $gateway->expects(self::once())->method('isMcpDisabledByAppPolicy')->willReturn(true);
        $gateway->expects(self::never())->method('startDeviceFlow');

        $handler = new AuthClientDeviceFlowEndpointsHandler(
            new StartDeviceFlowHandler(new TechnicalClientTokenPolicy(), $gateway),
            new PollDeviceFlowHandler($gateway),
            new CancelDeviceFlowHandler($gateway),
        );

        $result = $handler->start(['client_kind' => 'MCP']);

        self::assertSame(StartDeviceFlowEndpointResult::STATUS_FORBIDDEN_SCOPE, $result->status());
    }

    public function testPollReturnsThrottledWithRetryInSeconds(): void
    {
        $gateway = $this->createMock(DeviceFlowGateway::class);
        $gateway->expects(self::once())->method('pollDeviceFlow')->with('device-1')->willReturn([
            'status' => 'PENDING',
            'retry_in_seconds' => 4,
        ]);

        $handler = new AuthClientDeviceFlowEndpointsHandler(
            new StartDeviceFlowHandler(new TechnicalClientTokenPolicy(), $gateway),
            new PollDeviceFlowHandler($gateway),
            new CancelDeviceFlowHandler($gateway),
        );

        $result = $handler->poll(['device_code' => 'device-1']);

        self::assertSame(PollDeviceFlowEndpointResult::STATUS_THROTTLED, $result->status());
        self::assertSame(4, $result->retryInSeconds());
    }

    public function testCancelReturnsExpiredDeviceCode(): void
    {
        $gateway = $this->createMock(DeviceFlowGateway::class);
        $gateway->expects(self::once())->method('cancelDeviceFlow')->with('device-2')->willReturn(['status' => 'EXPIRED']);

        $handler = new AuthClientDeviceFlowEndpointsHandler(
            new StartDeviceFlowHandler(new TechnicalClientTokenPolicy(), $gateway),
            new PollDeviceFlowHandler($gateway),
            new CancelDeviceFlowHandler($gateway),
        );

        $result = $handler->cancel(['device_code' => 'device-2']);

        self::assertSame(CancelDeviceFlowEndpointResult::STATUS_EXPIRED_DEVICE_CODE, $result->status());
    }
}
