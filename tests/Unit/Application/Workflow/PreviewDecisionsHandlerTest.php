<?php

namespace App\Tests\Unit\Application\Workflow;

use App\Application\Workflow\Port\WorkflowGateway;
use App\Application\Workflow\PreviewDecisionsHandler;
use App\Application\Workflow\PreviewDecisionsResult;
use PHPUnit\Framework\TestCase;

final class PreviewDecisionsHandlerTest extends TestCase
{
    public function testHandleReturnsValidationFailedWhenActionOrUuidsMissing(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::never())->method('previewDecisions');

        $handler = new PreviewDecisionsHandler($gateway);

        self::assertSame(PreviewDecisionsResult::STATUS_VALIDATION_FAILED, $handler->handle('', ['a1'])->status());
        self::assertSame(PreviewDecisionsResult::STATUS_VALIDATION_FAILED, $handler->handle('KEEP', [])->status());
    }

    public function testHandleReturnsPreviewPayloadWhenInputValid(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::once())->method('previewDecisions')->with(['a1'], 'KEEP')->willReturn(['eligible_count' => 1]);

        $result = (new PreviewDecisionsHandler($gateway))->handle('KEEP', ['a1']);

        self::assertSame(PreviewDecisionsResult::STATUS_PREVIEWED, $result->status());
        self::assertSame(['eligible_count' => 1], $result->payload());
    }
}
