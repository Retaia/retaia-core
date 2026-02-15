<?php

namespace App\Tests\Unit\Application\Derived;

use App\Application\Derived\GetDerivedByKindHandler;
use App\Application\Derived\GetDerivedByKindResult;
use App\Application\Derived\Port\DerivedGateway;
use PHPUnit\Framework\TestCase;

final class GetDerivedByKindHandlerTest extends TestCase
{
    public function testHandleReturnsAssetNotFoundWhenAssetDoesNotExist(): void
    {
        $gateway = $this->createMock(DerivedGateway::class);
        $gateway->expects(self::once())->method('assetExists')->with('asset-1')->willReturn(false);

        $result = (new GetDerivedByKindHandler($gateway))->handle('asset-1', 'proxy_video');

        self::assertSame(GetDerivedByKindResult::STATUS_ASSET_NOT_FOUND, $result->status());
    }

    public function testHandleReturnsDerivedNotFoundWhenKindMissing(): void
    {
        $gateway = $this->createMock(DerivedGateway::class);
        $gateway->expects(self::once())->method('assetExists')->with('asset-1')->willReturn(true);
        $gateway->expects(self::once())->method('findDerivedByAssetAndKind')->with('asset-1', 'proxy_video')->willReturn(null);

        $result = (new GetDerivedByKindHandler($gateway))->handle('asset-1', 'proxy_video');

        self::assertSame(GetDerivedByKindResult::STATUS_DERIVED_NOT_FOUND, $result->status());
    }

    public function testHandleReturnsFoundWhenDerivedExists(): void
    {
        $gateway = $this->createMock(DerivedGateway::class);
        $gateway->expects(self::once())->method('assetExists')->with('asset-1')->willReturn(true);
        $gateway->expects(self::once())->method('findDerivedByAssetAndKind')->with('asset-1', 'proxy_video')->willReturn(['id' => 'd1']);

        $result = (new GetDerivedByKindHandler($gateway))->handle('asset-1', 'proxy_video');

        self::assertSame(GetDerivedByKindResult::STATUS_FOUND, $result->status());
        self::assertSame(['id' => 'd1'], $result->derived());
    }
}
