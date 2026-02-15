<?php

namespace App\Tests\Unit\Application\AuthClient;

use App\Application\AuthClient\AuthClientDeviceFlowEndpointsHandler;
use App\Application\AuthClient\CancelDeviceFlowEndpointResult;
use App\Application\AuthClient\CancelDeviceFlowHandler;
use App\Application\AuthClient\PollDeviceFlowEndpointResult;
use App\Application\AuthClient\PollDeviceFlowHandler;
use App\Application\AuthClient\Port\DeviceFlowStartRateLimiterGateway;
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
        $startRateLimiter = $this->createMock(DeviceFlowStartRateLimiterGateway::class);
        $startRateLimiter->expects(self::never())->method('retryInSecondsOrNull');

        $handler = new AuthClientDeviceFlowEndpointsHandler(
            new StartDeviceFlowHandler(new TechnicalClientTokenPolicy(), $gateway),
            new PollDeviceFlowHandler($gateway),
            new CancelDeviceFlowHandler($gateway),
            $startRateLimiter,
        );

        $result = $handler->start([], '10.0.0.1');

        self::assertSame(StartDeviceFlowEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testStartReturnsForbiddenScopeWhenMcpPolicyDisabled(): void
    {
        $gateway = $this->createMock(DeviceFlowGateway::class);
        $gateway->expects(self::once())->method('isMcpDisabledByAppPolicy')->willReturn(true);
        $gateway->expects(self::never())->method('startDeviceFlow');
        $startRateLimiter = $this->createMock(DeviceFlowStartRateLimiterGateway::class);
        $startRateLimiter->expects(self::once())
            ->method('retryInSecondsOrNull')
            ->with('MCP', '10.0.0.2')
            ->willReturn(null);

        $handler = new AuthClientDeviceFlowEndpointsHandler(
            new StartDeviceFlowHandler(new TechnicalClientTokenPolicy(), $gateway),
            new PollDeviceFlowHandler($gateway),
            new CancelDeviceFlowHandler($gateway),
            $startRateLimiter,
        );

        $result = $handler->start(['client_kind' => 'MCP'], '10.0.0.2');

        self::assertSame(StartDeviceFlowEndpointResult::STATUS_FORBIDDEN_SCOPE, $result->status());
    }

    public function testPollReturnsThrottledWithRetryInSeconds(): void
    {
        $gateway = $this->createMock(DeviceFlowGateway::class);
        $gateway->expects(self::once())->method('pollDeviceFlow')->with('device-1')->willReturn([
            'status' => 'PENDING',
            'retry_in_seconds' => 4,
        ]);
        $startRateLimiter = $this->createMock(DeviceFlowStartRateLimiterGateway::class);

        $handler = new AuthClientDeviceFlowEndpointsHandler(
            new StartDeviceFlowHandler(new TechnicalClientTokenPolicy(), $gateway),
            new PollDeviceFlowHandler($gateway),
            new CancelDeviceFlowHandler($gateway),
            $startRateLimiter,
        );

        $result = $handler->poll(['device_code' => 'device-1']);

        self::assertSame(PollDeviceFlowEndpointResult::STATUS_THROTTLED, $result->status());
        self::assertSame(4, $result->retryInSeconds());
    }

    public function testCancelReturnsExpiredDeviceCode(): void
    {
        $gateway = $this->createMock(DeviceFlowGateway::class);
        $gateway->expects(self::once())->method('cancelDeviceFlow')->with('device-2')->willReturn(['status' => 'EXPIRED']);
        $startRateLimiter = $this->createMock(DeviceFlowStartRateLimiterGateway::class);

        $handler = new AuthClientDeviceFlowEndpointsHandler(
            new StartDeviceFlowHandler(new TechnicalClientTokenPolicy(), $gateway),
            new PollDeviceFlowHandler($gateway),
            new CancelDeviceFlowHandler($gateway),
            $startRateLimiter,
        );

        $result = $handler->cancel(['device_code' => 'device-2']);

        self::assertSame(CancelDeviceFlowEndpointResult::STATUS_EXPIRED_DEVICE_CODE, $result->status());
    }

    public function testStartReturnsTooManyAttemptsWhenRateLimited(): void
    {
        $gateway = $this->createMock(DeviceFlowGateway::class);
        $gateway->expects(self::never())->method('startDeviceFlow');
        $gateway->expects(self::never())->method('isMcpDisabledByAppPolicy');
        $startRateLimiter = $this->createMock(DeviceFlowStartRateLimiterGateway::class);
        $startRateLimiter->expects(self::once())
            ->method('retryInSecondsOrNull')
            ->with('AGENT', '10.0.0.3')
            ->willReturn(42);

        $handler = new AuthClientDeviceFlowEndpointsHandler(
            new StartDeviceFlowHandler(new TechnicalClientTokenPolicy(), $gateway),
            new PollDeviceFlowHandler($gateway),
            new CancelDeviceFlowHandler($gateway),
            $startRateLimiter,
        );

        $result = $handler->start(['client_kind' => 'AGENT'], '10.0.0.3');

        self::assertSame(StartDeviceFlowEndpointResult::STATUS_TOO_MANY_ATTEMPTS, $result->status());
        self::assertSame(42, $result->retryInSeconds());
    }
}
