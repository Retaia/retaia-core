<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\TwoFactorDisableEndpointResult;
use PHPUnit\Framework\TestCase;

final class TwoFactorDisableEndpointResultTest extends TestCase
{
    public function testExposesGivenStatus(): void
    {
        $result = new TwoFactorDisableEndpointResult(TwoFactorDisableEndpointResult::STATUS_DISABLED);

        self::assertSame(TwoFactorDisableEndpointResult::STATUS_DISABLED, $result->status());
    }
}
