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
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('auth.error.too_many_2fa_attempts')
            ->willReturn('auth.error.too_many_2fa_attempts');
        $limiter = new ApiLoginSecondFactorAttemptLimiter(
            $this->limiterFactory(1, '1 minute'),
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

        $retryAfter = $response->headers->get('Retry-After');
        self::assertNotNull($retryAfter);
        self::assertNotSame('', $retryAfter);
        self::assertGreaterThanOrEqual(0, (int) $retryAfter);
        self::assertLessThanOrEqual(60, (int) $retryAfter);
    }

    public function testDifferentUsersOnSameIpHaveIndependentLimits(): void
    {
        $limiter = new ApiLoginSecondFactorAttemptLimiter(
            $this->limiterFactory(1, '1 minute'),
            $this->translator()
        );

        self::assertTrue($limiter->consume('u-1', '127.0.0.1'));
        self::assertFalse($limiter->consume('u-1', '127.0.0.1'));
        self::assertTrue($limiter->consume('u-2', '127.0.0.1'));
        self::assertFalse($limiter->consume('u-2', '127.0.0.1'));
    }

    public function testSameUserOnDifferentIpsHaveIndependentLimits(): void
    {
        $limiter = new ApiLoginSecondFactorAttemptLimiter(
            $this->limiterFactory(1, '1 minute'),
            $this->translator()
        );

        self::assertTrue($limiter->consume('u-1', '127.0.0.1'));
        self::assertTrue($limiter->consume('u-1', '192.168.0.1'));
        self::assertFalse($limiter->consume('u-1', '127.0.0.1'));
        self::assertFalse($limiter->consume('u-1', '192.168.0.1'));
    }

    private function limiterFactory(int $limit, string $interval): RateLimiterFactory
    {
        return new RateLimiterFactory([
            'id' => 'test-auth-2fa-limit',
            'policy' => 'fixed_window',
            'limit' => $limit,
            'interval' => $interval,
        ], new InMemoryStorage());
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
