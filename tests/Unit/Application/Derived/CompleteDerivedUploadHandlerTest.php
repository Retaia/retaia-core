<?php

namespace App\Tests\Unit\Application\Derived;

use App\Application\Derived\CompleteDerivedUploadHandler;
use App\Application\Derived\CompleteDerivedUploadResult;
use App\Application\Derived\Port\DerivedGateway;
use PHPUnit\Framework\TestCase;

final class CompleteDerivedUploadHandlerTest extends TestCase
{
    public function testHandleReturnsNotFoundWhenAssetDoesNotExist(): void
    {
        $gateway = $this->createMock(DerivedGateway::class);
        $gateway->expects(self::once())->method('assetExists')->with('asset-1')->willReturn(false);

        $result = (new CompleteDerivedUploadHandler($gateway))->handle('asset-1', 'upload-1', 1);

        self::assertSame(CompleteDerivedUploadResult::STATUS_NOT_FOUND, $result->status());
    }

    public function testHandleReturnsConflictWhenCompletionFails(): void
    {
        $gateway = $this->createMock(DerivedGateway::class);
        $gateway->expects(self::once())->method('assetExists')->with('asset-1')->willReturn(true);
        $gateway->expects(self::once())->method('completeUpload')->with('asset-1', 'upload-1', 1)->willReturn(null);

        $result = (new CompleteDerivedUploadHandler($gateway))->handle('asset-1', 'upload-1', 1);

        self::assertSame(CompleteDerivedUploadResult::STATUS_STATE_CONFLICT, $result->status());
    }

    public function testHandleReturnsCompletedWhenUploadCompletes(): void
    {
        $gateway = $this->createMock(DerivedGateway::class);
        $gateway->expects(self::once())->method('assetExists')->with('asset-1')->willReturn(true);
        $gateway->expects(self::once())->method('completeUpload')->with('asset-1', 'upload-1', 1)->willReturn(['id' => 'd1']);

        $result = (new CompleteDerivedUploadHandler($gateway))->handle('asset-1', 'upload-1', 1);

        self::assertSame(CompleteDerivedUploadResult::STATUS_COMPLETED, $result->status());
        self::assertSame(['id' => 'd1'], $result->derived());
    }
}
