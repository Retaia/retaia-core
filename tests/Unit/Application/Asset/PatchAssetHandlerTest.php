<?php

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\PatchAssetHandler;
use App\Application\Asset\PatchAssetResult;
use App\Application\Asset\Port\AssetPatchGateway;
use PHPUnit\Framework\TestCase;

final class PatchAssetHandlerTest extends TestCase
{
    public function testHandlePassesThroughGatewayResult(): void
    {
        $gateway = $this->createMock(AssetPatchGateway::class);
        $gateway->expects(self::once())->method('patch')->with('a1', ['notes' => 'x'])->willReturn([
            'status' => PatchAssetResult::STATUS_PATCHED,
            'payload' => ['uuid' => 'a1', 'notes' => 'x'],
        ]);

        $result = (new PatchAssetHandler($gateway))->handle('a1', ['notes' => 'x']);

        self::assertSame(PatchAssetResult::STATUS_PATCHED, $result->status());
        self::assertSame(['uuid' => 'a1', 'notes' => 'x'], $result->payload());
    }
}
