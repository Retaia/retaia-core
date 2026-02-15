<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\Port\AgentActorGateway;
use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Auth\ResolveAgentActorResult;
use PHPUnit\Framework\TestCase;

final class ResolveAgentActorHandlerTest extends TestCase
{
    public function testHandleReturnsForbiddenWhenActorIsNotAgent(): void
    {
        $gateway = $this->createMock(AgentActorGateway::class);
        $gateway->expects(self::once())->method('isAgent')->willReturn(false);

        $result = (new ResolveAgentActorHandler($gateway))->handle();

        self::assertSame(ResolveAgentActorResult::STATUS_FORBIDDEN_ACTOR, $result->status());
    }

    public function testHandleReturnsAuthorizedWhenActorIsAgent(): void
    {
        $gateway = $this->createMock(AgentActorGateway::class);
        $gateway->expects(self::once())->method('isAgent')->willReturn(true);

        $result = (new ResolveAgentActorHandler($gateway))->handle();

        self::assertSame(ResolveAgentActorResult::STATUS_AUTHORIZED, $result->status());
    }
}
