<?php

namespace App\Tests\Unit\Application\Derived;

use App\Application\Derived\Port\DerivedGateway;
use App\Application\Derived\UploadDerivedPartHandler;
use App\Application\Derived\UploadDerivedPartResult;
use PHPUnit\Framework\TestCase;

final class UploadDerivedPartHandlerTest extends TestCase
{
    public function testHandleReturnsNotFoundWhenAssetDoesNotExist(): void
    {
        $gateway = $this->createMock(DerivedGateway::class);
        $gateway->expects(self::once())->method('assetExists')->with('asset-1')->willReturn(false);

        $result = (new UploadDerivedPartHandler($gateway))->handle('asset-1', 'upload-1', 1);

        self::assertSame(UploadDerivedPartResult::STATUS_NOT_FOUND, $result->status());
    }

    public function testHandleReturnsConflictWhenAddPartFails(): void
    {
        $gateway = $this->createMock(DerivedGateway::class);
        $gateway->expects(self::once())->method('assetExists')->with('asset-1')->willReturn(true);
        $gateway->expects(self::once())->method('addUploadPart')->with('upload-1', 1)->willReturn(false);

        $result = (new UploadDerivedPartHandler($gateway))->handle('asset-1', 'upload-1', 1);

        self::assertSame(UploadDerivedPartResult::STATUS_STATE_CONFLICT, $result->status());
    }

    public function testHandleReturnsAcceptedWhenPartIsAdded(): void
    {
        $gateway = $this->createMock(DerivedGateway::class);
        $gateway->expects(self::once())->method('assetExists')->with('asset-1')->willReturn(true);
        $gateway->expects(self::once())->method('addUploadPart')->with('upload-1', 1)->willReturn(true);

        $result = (new UploadDerivedPartHandler($gateway))->handle('asset-1', 'upload-1', 1);

        self::assertSame(UploadDerivedPartResult::STATUS_ACCEPTED, $result->status());
    }
}
