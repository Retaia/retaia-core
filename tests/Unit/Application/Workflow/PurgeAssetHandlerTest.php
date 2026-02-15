<?php

namespace App\Tests\Unit\Application\Workflow;

use App\Application\Workflow\Port\WorkflowGateway;
use App\Application\Workflow\PurgeAssetHandler;
use App\Application\Workflow\PurgeAssetResult;
use PHPUnit\Framework\TestCase;

final class PurgeAssetHandlerTest extends TestCase
{
    public function testHandleMapsGatewayStatuses(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::exactly(3))->method('purge')->with('a1')->willReturnOnConsecutiveCalls(
            ['status' => PurgeAssetResult::STATUS_NOT_FOUND, 'asset' => null],
            ['status' => PurgeAssetResult::STATUS_STATE_CONFLICT, 'asset' => null],
            ['status' => PurgeAssetResult::STATUS_PURGED, 'asset' => ['uuid' => 'a1', 'state' => 'PURGED']]
        );

        $handler = new PurgeAssetHandler($gateway);

        self::assertSame(PurgeAssetResult::STATUS_NOT_FOUND, $handler->handle('a1')->status());
        self::assertSame(PurgeAssetResult::STATUS_STATE_CONFLICT, $handler->handle('a1')->status());

        $purged = $handler->handle('a1');
        self::assertSame(PurgeAssetResult::STATUS_PURGED, $purged->status());
        self::assertSame(['uuid' => 'a1', 'state' => 'PURGED'], $purged->payload());
    }
}
