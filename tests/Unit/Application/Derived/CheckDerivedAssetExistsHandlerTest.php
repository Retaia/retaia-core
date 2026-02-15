<?php

namespace App\Tests\Unit\Application\Derived;

use App\Application\Derived\CheckDerivedAssetExistsHandler;
use App\Application\Derived\Port\DerivedGateway;
use PHPUnit\Framework\TestCase;

final class CheckDerivedAssetExistsHandlerTest extends TestCase
{
    public function testHandleDelegatesToGateway(): void
    {
        $gateway = $this->createMock(DerivedGateway::class);
        $gateway->expects(self::exactly(2))->method('assetExists')->with('asset-1')->willReturnOnConsecutiveCalls(true, false);

        $handler = new CheckDerivedAssetExistsHandler($gateway);

        self::assertTrue($handler->handle('asset-1'));
        self::assertFalse($handler->handle('asset-1'));
    }
}
