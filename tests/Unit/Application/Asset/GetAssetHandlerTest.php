<?php

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\GetAssetHandler;
use App\Application\Asset\GetAssetResult;
use App\Application\Asset\Port\AssetReadGateway;
use PHPUnit\Framework\TestCase;

final class GetAssetHandlerTest extends TestCase
{
    public function testHandleReturnsNotFoundWhenGatewayReturnsNull(): void
    {
        $gateway = $this->createMock(AssetReadGateway::class);
        $gateway->expects(self::once())->method('getByUuid')->with('a1')->willReturn(null);

        $result = (new GetAssetHandler($gateway))->handle('a1');

        self::assertSame(GetAssetResult::STATUS_NOT_FOUND, $result->status());
        self::assertNull($result->asset());
    }

    public function testHandleReturnsAssetWhenGatewayFindsOne(): void
    {
        $gateway = $this->createMock(AssetReadGateway::class);
        $gateway->expects(self::once())->method('getByUuid')->with('a1')->willReturn([
            'uuid' => 'a1',
            'state' => 'READY',
        ]);

        $result = (new GetAssetHandler($gateway))->handle('a1');

        self::assertSame(GetAssetResult::STATUS_FOUND, $result->status());
        self::assertSame(['uuid' => 'a1', 'state' => 'READY'], $result->asset());
    }
}
