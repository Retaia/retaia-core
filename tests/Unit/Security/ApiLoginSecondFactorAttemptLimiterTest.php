<?php

namespace App\Tests\Unit\Security;

use App\Security\ApiLoginSecondFactorAttemptLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApiLoginSecondFactorAttemptLimiterTest extends TestCase
{
    public function testConsumeRejectsAfterConfiguredLimitAndBuildsResponse(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $limiter = new ApiLoginSecondFactorAttemptLimiter(
            new RateLimiterFactory([
                'id' => 'test-auth-2fa-limit',
                'policy' => 'fixed_window',
                'limit' => 1,
                'interval' => '1 minute',
            ], new InMemoryStorage()),
            $translator
        );

        self::assertTrue($limiter->consume('u-1', '127.0.0.1'));
        self::assertFalse($limiter->consume('u-1', '127.0.0.1'));
        $response = $limiter->tooManyAttemptsResponse();

        self::assertSame(429, $response->getStatusCode());
        self::assertSame([
            'code' => 'TOO_MANY_ATTEMPTS',
            'message' => 'auth.error.too_many_2fa_attempts',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }
}
