<?php

namespace App\Tests\Unit\Domain\AuthClient;

use App\Domain\AuthClient\TechnicalClientTokenPolicy;
use PHPUnit\Framework\TestCase;

final class TechnicalClientTokenPolicyTest extends TestCase
{
    public function testUiRustIsForbiddenActorForTechnicalTokenMint(): void
    {
        $policy = new TechnicalClientTokenPolicy();

        self::assertTrue($policy->isForbiddenActor('UI_RUST'));
        self::assertFalse($policy->isForbiddenActor('AGENT'));
        self::assertFalse($policy->isForbiddenActor('MCP'));
    }

    public function testMcpCanBeForbiddenByAppSwitch(): void
    {
        $policy = new TechnicalClientTokenPolicy();

        self::assertTrue($policy->isForbiddenScope('MCP', true));
        self::assertFalse($policy->isForbiddenScope('MCP', false));
        self::assertFalse($policy->isForbiddenScope('AGENT', true));
    }
}
