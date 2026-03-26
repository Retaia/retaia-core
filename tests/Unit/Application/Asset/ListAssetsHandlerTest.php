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

        $result = (new ListAssetsHandler($gateway))->handle([], null, null, null, null, null, 10, null, ['wedding'], 'XOR', null, null, null, null);

        self::assertSame(ListAssetsResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleReturnsForbiddenScopeWhenGatewayReturnsNull(): void
    {
        $gateway = $this->createMock(AssetReadGateway::class);
        $gateway->expects(self::once())->method('list')->willReturn(null);

        $result = (new ListAssetsHandler($gateway))->handle([], null, null, null, null, null, 10, null, ['wedding'], 'AND', null, null, null, null);

        self::assertSame(ListAssetsResult::STATUS_FORBIDDEN_SCOPE, $result->status());
        self::assertSame([], $result->items());
    }

    public function testHandleReturnsItemsFromGateway(): void
    {
        $gateway = $this->createMock(AssetReadGateway::class);
        $gateway->expects(self::once())->method('list')->with(['READY'], 'VIDEO', 'rush', '-created_at', null, null, 10, 0, ['wedding'], 'OR', true, 'BE', 'Brussels', [
            'min_lon' => 4.3,
            'min_lat' => 50.8,
            'max_lon' => 4.45,
            'max_lat' => 50.92,
        ])->willReturn([
            'items' => [['uuid' => 'a1', 'state' => 'READY']],
            'has_more' => true,
        ]);

        $result = (new ListAssetsHandler($gateway))->handle(['READY'], 'VIDEO', 'rush', null, null, null, 10, null, ['wedding'], 'OR', true, 'BE', 'Brussels', '4.3,50.8,4.45,50.92');

        self::assertSame(ListAssetsResult::STATUS_OK, $result->status());
        self::assertSame([['uuid' => 'a1', 'state' => 'READY']], $result->items());
        self::assertNotNull($result->nextCursor());
    }

    public function testHandleReturnsValidationFailedForInvalidSort(): void
    {
        $gateway = $this->createMock(AssetReadGateway::class);
        $gateway->expects(self::never())->method('list');

        $result = (new ListAssetsHandler($gateway))->handle([], null, null, 'invalid-sort', null, null, 10, null, [], 'AND', null, null, null, null);

        self::assertSame(ListAssetsResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleReturnsValidationFailedForInvalidCapturedAtRange(): void
    {
        $gateway = $this->createMock(AssetReadGateway::class);
        $gateway->expects(self::never())->method('list');

        $result = (new ListAssetsHandler($gateway))->handle(
            [],
            null,
            null,
            '-created_at',
            '2026-01-31T00:00:00Z',
            '2026-01-01T00:00:00Z',
            10,
            null,
            [],
            'AND',
            null,
            null,
            null,
            null
        );

        self::assertSame(ListAssetsResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleReturnsValidationFailedForInvalidState(): void
    {
        $gateway = $this->createMock(AssetReadGateway::class);
        $gateway->expects(self::never())->method('list');

        $result = (new ListAssetsHandler($gateway))->handle(['NOPE'], null, null, null, null, null, 10, null, [], 'AND', null, null, null, null);

        self::assertSame(ListAssetsResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleReturnsValidationFailedForInvalidGeoBbox(): void
    {
        $gateway = $this->createMock(AssetReadGateway::class);
        $gateway->expects(self::never())->method('list');

        $result = (new ListAssetsHandler($gateway))->handle([], null, null, null, null, null, 10, null, [], 'AND', null, null, null, '4.5,50.8,4.3,50.9');

        self::assertSame(ListAssetsResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleReturnsValidationFailedForCursorMismatch(): void
    {
        $gateway = $this->createMock(AssetReadGateway::class);
        $gateway->expects(self::never())->method('list');

        $cursor = rtrim(strtr(base64_encode(json_encode(['offset' => 1, 'context_hash' => 'wrong'], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $result = (new ListAssetsHandler($gateway))->handle([], null, null, null, null, null, 10, $cursor, [], 'AND', null, null, null, null);

        self::assertSame(ListAssetsResult::STATUS_VALIDATION_FAILED, $result->status());
    }
}
