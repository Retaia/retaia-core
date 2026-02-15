<?php

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\DecideAssetHandler;
use App\Application\Asset\DecideAssetResult;
use App\Application\Asset\Port\AssetWorkflowGateway;
use PHPUnit\Framework\TestCase;

final class DecideAssetHandlerTest extends TestCase
{
    public function testHandlePassesThroughGatewayResult(): void
    {
        $gateway = $this->createMock(AssetWorkflowGateway::class);
        $gateway->expects(self::once())->method('decide')->with('a1', 'KEEP')->willReturn([
            'status' => DecideAssetResult::STATUS_DECIDED,
            'payload' => ['uuid' => 'a1', 'state' => 'DECIDED_KEEP'],
        ]);

        $result = (new DecideAssetHandler($gateway))->handle('a1', 'KEEP');

        self::assertSame(DecideAssetResult::STATUS_DECIDED, $result->status());
        self::assertSame(['uuid' => 'a1', 'state' => 'DECIDED_KEEP'], $result->payload());
    }
}
