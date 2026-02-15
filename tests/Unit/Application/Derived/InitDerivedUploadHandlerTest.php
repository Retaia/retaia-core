<?php

namespace App\Tests\Unit\Application\Derived;

use App\Application\Derived\InitDerivedUploadHandler;
use App\Application\Derived\InitDerivedUploadResult;
use App\Application\Derived\Port\DerivedGateway;
use PHPUnit\Framework\TestCase;

final class InitDerivedUploadHandlerTest extends TestCase
{
    public function testHandleReturnsNotFoundWhenAssetDoesNotExist(): void
    {
        $gateway = $this->createMock(DerivedGateway::class);
        $gateway->expects(self::once())->method('assetExists')->with('asset-1')->willReturn(false);
        $gateway->expects(self::never())->method('initUpload');

        $result = (new InitDerivedUploadHandler($gateway))->handle('asset-1', 'proxy_video', 'video/mp4', 1024, null);

        self::assertSame(InitDerivedUploadResult::STATUS_NOT_FOUND, $result->status());
    }

    public function testHandleReturnsSessionWhenAssetExists(): void
    {
        $gateway = $this->createMock(DerivedGateway::class);
        $gateway->expects(self::once())->method('assetExists')->with('asset-1')->willReturn(true);
        $gateway->expects(self::once())->method('initUpload')->with('asset-1', 'proxy_video', 'video/mp4', 1024, null)->willReturn(['upload_id' => 'abc']);

        $result = (new InitDerivedUploadHandler($gateway))->handle('asset-1', 'proxy_video', 'video/mp4', 1024, null);

        self::assertSame(InitDerivedUploadResult::STATUS_INITIALIZED, $result->status());
        self::assertSame(['upload_id' => 'abc'], $result->session());
    }
}
