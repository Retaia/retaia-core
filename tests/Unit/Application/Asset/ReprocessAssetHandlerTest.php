<?php

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\Port\AssetWorkflowGateway;
use App\Application\Asset\ReprocessAssetHandler;
use App\Application\Asset\ReprocessAssetResult;
use PHPUnit\Framework\TestCase;

final class ReprocessAssetHandlerTest extends TestCase
{
    public function testHandlePassesThroughGatewayResult(): void
    {
        $gateway = $this->createMock(AssetWorkflowGateway::class);
        $gateway->expects(self::once())->method('reprocess')->with('a1')->willReturn([
            'status' => ReprocessAssetResult::STATUS_REPROCESSED,
            'payload' => ['uuid' => 'a1', 'state' => 'READY'],
        ]);

        $result = (new ReprocessAssetHandler($gateway))->handle('a1');

        self::assertSame(ReprocessAssetResult::STATUS_REPROCESSED, $result->status());
        self::assertSame(['uuid' => 'a1', 'state' => 'READY'], $result->payload());
    }
}
