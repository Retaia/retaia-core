<?php

namespace App\Tests\Unit\Domain\AuthClient;

use App\Domain\AuthClient\TechnicalClientAdminPolicy;
use PHPUnit\Framework\TestCase;

final class TechnicalClientAdminPolicyTest extends TestCase
{
    public function testUiRustIsProtectedFromAdminRevoke(): void
    {
        $policy = new TechnicalClientAdminPolicy();

        self::assertTrue($policy->isRevokeForbiddenScope('UI_RUST'));
        self::assertFalse($policy->isRevokeForbiddenScope('AGENT'));
        self::assertFalse($policy->isRevokeForbiddenScope('MCP'));
        self::assertFalse($policy->isRevokeForbiddenScope(null));
    }
}
