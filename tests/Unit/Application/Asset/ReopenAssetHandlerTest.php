<?php

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\Port\AssetWorkflowGateway;
use App\Application\Asset\ReopenAssetHandler;
use App\Application\Asset\ReopenAssetResult;
use PHPUnit\Framework\TestCase;

final class ReopenAssetHandlerTest extends TestCase
{
    public function testHandlePassesThroughGatewayResult(): void
    {
        $gateway = $this->createMock(AssetWorkflowGateway::class);
        $gateway->expects(self::once())->method('reopen')->with('a1')->willReturn([
            'status' => ReopenAssetResult::STATUS_REOPENED,
            'payload' => ['uuid' => 'a1', 'state' => 'DECISION_PENDING'],
        ]);

        $result = (new ReopenAssetHandler($gateway))->handle('a1');

        self::assertSame(ReopenAssetResult::STATUS_REOPENED, $result->status());
        self::assertSame(['uuid' => 'a1', 'state' => 'DECISION_PENDING'], $result->payload());
    }
}
