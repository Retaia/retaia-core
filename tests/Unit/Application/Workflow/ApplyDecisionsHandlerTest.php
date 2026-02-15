<?php

namespace App\Tests\Unit\Application\Workflow;

use App\Application\Workflow\ApplyDecisionsHandler;
use App\Application\Workflow\ApplyDecisionsResult;
use App\Application\Workflow\Port\WorkflowGateway;
use PHPUnit\Framework\TestCase;

final class ApplyDecisionsHandlerTest extends TestCase
{
    public function testHandleReturnsValidationFailedWhenActionOrUuidsMissing(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::never())->method('applyDecisions');

        $handler = new ApplyDecisionsHandler($gateway);

        self::assertSame(ApplyDecisionsResult::STATUS_VALIDATION_FAILED, $handler->handle('', ['a1'])->status());
        self::assertSame(ApplyDecisionsResult::STATUS_VALIDATION_FAILED, $handler->handle('KEEP', [])->status());
    }

    public function testHandleReturnsApplyPayloadWhenInputValid(): void
    {
        $gateway = $this->createMock(WorkflowGateway::class);
        $gateway->expects(self::once())->method('applyDecisions')->with(['a1'], 'KEEP')->willReturn(['applied_count' => 1]);

        $result = (new ApplyDecisionsHandler($gateway))->handle('KEEP', ['a1']);

        self::assertSame(ApplyDecisionsResult::STATUS_APPLIED, $result->status());
        self::assertSame(['applied_count' => 1], $result->payload());
    }
}
