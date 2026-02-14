<?php

namespace App\Tests\Unit\Application\AuthClient;

use App\Application\AuthClient\Port\DeviceFlowGateway;
use App\Application\AuthClient\StartDeviceFlowHandler;
use App\Application\AuthClient\StartDeviceFlowResult;
use App\Domain\AuthClient\TechnicalClientTokenPolicy;
use PHPUnit\Framework\TestCase;

final class StartDeviceFlowHandlerTest extends TestCase
{
    public function testReturnsForbiddenActorForUnsupportedClientKind(): void
    {
        $gateway = $this->createStub(DeviceFlowGateway::class);
        $handler = new StartDeviceFlowHandler(new TechnicalClientTokenPolicy(), $gateway);

        $result = $handler->handle('BACKOFFICE');

        self::assertSame(StartDeviceFlowResult::STATUS_FORBIDDEN_ACTOR, $result->status());
    }

    public function testReturnsForbiddenScopeForMcpWhenAppDisabled(): void
    {
        $gateway = $this->createMock(DeviceFlowGateway::class);
        $gateway->expects(self::once())->method('isMcpDisabledByAppPolicy')->willReturn(true);

        $handler = new StartDeviceFlowHandler(new TechnicalClientTokenPolicy(), $gateway);
        $result = $handler->handle('MCP');

        self::assertSame(StartDeviceFlowResult::STATUS_FORBIDDEN_SCOPE, $result->status());
    }

    public function testReturnsSuccessWithFlowPayload(): void
    {
        $gateway = $this->createMock(DeviceFlowGateway::class);
        $gateway->method('isMcpDisabledByAppPolicy')->willReturn(false);
        $gateway->expects(self::once())->method('startDeviceFlow')->with('AGENT')->willReturn([
            'device_code' => 'dc_123',
            'user_code' => 'ABCD1234',
            'verification_uri' => '/device',
            'verification_uri_complete' => '/device?user_code=ABCD1234',
            'expires_in' => 600,
            'interval' => 5,
        ]);

        $handler = new StartDeviceFlowHandler(new TechnicalClientTokenPolicy(), $gateway);
        $result = $handler->handle('AGENT');

        self::assertSame(StartDeviceFlowResult::STATUS_SUCCESS, $result->status());
        self::assertSame('dc_123', $result->payload()['device_code'] ?? null);
    }
}
