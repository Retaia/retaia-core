<?php

namespace App\Tests\Integration\Security;

use App\Security\ApiLoginSecondFactorAttemptLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApiLoginSecondFactorAttemptLimiterIntegrationTest extends TestCase
{
    public function testAllowsAttemptsAgainAfterIntervalExpires(): void
    {
        $limiter = new ApiLoginSecondFactorAttemptLimiter(
            new RateLimiterFactory([
                'id' => 'test-auth-2fa-limit-reset',
                'policy' => 'fixed_window',
                'limit' => 1,
                'interval' => '1 second',
            ], new InMemoryStorage()),
            $this->translator()
        );

        self::assertTrue($limiter->consume('u-1', '127.0.0.1'));
        self::assertFalse($limiter->consume('u-1', '127.0.0.1'));

        sleep(2);

        self::assertTrue($limiter->consume('u-1', '127.0.0.1'));
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
