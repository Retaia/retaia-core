<?php

namespace App\Tests\Unit\Controller;

use App\Tests\Support\TranslatorStubTrait;
use App\Controller\Api\AuthApiErrorResponder;
use App\Controller\Api\AuthRateLimitGuard;
use App\Controller\Api\AuthSessionHttpResponder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class AuthRateLimitGuardTest extends TestCase
{
    use TranslatorStubTrait;

    public function testConsumeRefreshReturnsThrottleResponseWhenRejected(): void
    {
        $refreshLimiter = $this->rateLimiterFactory();
        $refreshLimiter->create('k1')->consume(1);

        $guard = new AuthRateLimitGuard(
            new AuthApiErrorResponder($this->translatorStub()),
            new AuthSessionHttpResponder(new AuthApiErrorResponder($this->translatorStub())),
            $refreshLimiter,
            $this->rateLimiterFactory(),
        );

        $response = $guard->consumeRefresh('k1');

        self::assertSame(429, $response?->getStatusCode());
        self::assertSame('TOO_MANY_ATTEMPTS', json_decode((string) $response?->getContent(), true, 512, JSON_THROW_ON_ERROR)['code'] ?? null);
    }

    public function testConsumeTwoFactorManageReturnsNullWhenAccepted(): void
    {
        $guard = new AuthRateLimitGuard(
            new AuthApiErrorResponder($this->translatorStub()),
            new AuthSessionHttpResponder(new AuthApiErrorResponder($this->translatorStub())),
            $this->rateLimiterFactory(),
            $this->rateLimiterFactory(),
        );

        self::assertNull($guard->consumeTwoFactorManage('u1', 'setup', '127.0.0.1'));
    }


    private function rateLimiterFactory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );
    }
}
