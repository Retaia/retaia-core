<?php

namespace App\Tests\Unit\Application\Derived;

use App\Application\Derived\ListDerivedFilesHandler;
use App\Application\Derived\ListDerivedFilesResult;
use App\Application\Derived\Port\DerivedGateway;
use PHPUnit\Framework\TestCase;

final class ListDerivedFilesHandlerTest extends TestCase
{
    public function testHandleReturnsNotFoundWhenAssetDoesNotExist(): void
    {
        $gateway = $this->createMock(DerivedGateway::class);
        $gateway->expects(self::once())->method('assetExists')->with('asset-1')->willReturn(false);

        $result = (new ListDerivedFilesHandler($gateway))->handle('asset-1');

        self::assertSame(ListDerivedFilesResult::STATUS_NOT_FOUND, $result->status());
    }

    public function testHandleReturnsItemsWhenAssetExists(): void
    {
        $gateway = $this->createMock(DerivedGateway::class);
        $gateway->expects(self::once())->method('assetExists')->with('asset-1')->willReturn(true);
        $gateway->expects(self::once())->method('listDerivedForAsset')->with('asset-1')->willReturn([['id' => 'd1']]);

        $result = (new ListDerivedFilesHandler($gateway))->handle('asset-1');

        self::assertSame(ListDerivedFilesResult::STATUS_FOUND, $result->status());
        self::assertSame([['id' => 'd1']], $result->items());
    }
}
