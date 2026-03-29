<?php

namespace App\Tests\Unit\User;

use App\User\Service\TwoFactorRecoveryCodeService;
use PHPUnit\Framework\TestCase;

final class TwoFactorRecoveryCodeServiceTest extends TestCase
{
    public function testConsumeRecoveryCodeSupportsLegacySha256AndRemovesIt(): void
    {
        $service = new TwoFactorRecoveryCodeService();
        $state = [
            'enabled' => true,
            'recovery_code_hashes' => [],
            'recovery_code_sha256' => [hash('sha256', 'ABC12345')],
        ];

        self::assertTrue($service->consumeRecoveryCode($state, 'ABC12345'));
        self::assertSame([], $state['recovery_code_hashes']);
        self::assertArrayNotHasKey('recovery_code_sha256', $state);
    }
}
