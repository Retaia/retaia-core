<?php

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\ListAssetsHandler;
use App\Application\Asset\ListAssetsResult;
use App\Application\Asset\Port\AssetReadGateway;
use PHPUnit\Framework\TestCase;

final class ListAssetsHandlerTest extends TestCase
{
    public function testHandleReturnsValidationFailedForInvalidMode(): void
    {
        $gateway = $this->createMock(AssetReadGateway::class);
        $gateway->expects(self::never())->method('list');

        $result = (new ListAssetsHandler($gateway))->handle(null, null, null, 10, ['wedding'], 'XOR');

        self::assertSame(ListAssetsResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleReturnsForbiddenScopeWhenGatewayReturnsNull(): void
    {
        $gateway = $this->createMock(AssetReadGateway::class);
        $gateway->expects(self::once())->method('list')->willReturn(null);

        $result = (new ListAssetsHandler($gateway))->handle(null, null, null, 10, ['wedding'], 'AND');

        self::assertSame(ListAssetsResult::STATUS_FORBIDDEN_SCOPE, $result->status());
        self::assertSame([], $result->items());
    }

    public function testHandleReturnsItemsFromGateway(): void
    {
        $gateway = $this->createMock(AssetReadGateway::class);
        $gateway->expects(self::once())->method('list')->with('READY', 'VIDEO', 'rush', 10, ['wedding'], 'OR')->willReturn([
            ['uuid' => 'a1', 'state' => 'READY'],
        ]);

        $result = (new ListAssetsHandler($gateway))->handle('READY', 'VIDEO', 'rush', 10, ['wedding'], 'OR');

        self::assertSame(ListAssetsResult::STATUS_OK, $result->status());
        self::assertSame([['uuid' => 'a1', 'state' => 'READY']], $result->items());
    }
}
