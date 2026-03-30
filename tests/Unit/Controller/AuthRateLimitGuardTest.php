<?php

namespace App\Tests\Unit\Controller;

use App\Controller\Api\AuthApiErrorResponder;
use App\Controller\Api\AuthRateLimitGuard;
use App\Controller\Api\AuthSessionHttpResponder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AuthRateLimitGuardTest extends TestCase
{
    public function testConsumeRefreshReturnsThrottleResponseWhenRejected(): void
    {
        $refreshLimiter = $this->rateLimiterFactory();
        $refreshLimiter->create('k1')->consume(1);

        $guard = new AuthRateLimitGuard(
            new AuthApiErrorResponder($this->translator()),
            new AuthSessionHttpResponder(new AuthApiErrorResponder($this->translator())),
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
            new AuthApiErrorResponder($this->translator()),
            new AuthSessionHttpResponder(new AuthApiErrorResponder($this->translator())),
            $this->rateLimiterFactory(),
            $this->rateLimiterFactory(),
        );

        self::assertNull($guard->consumeTwoFactorManage('u1', 'setup', '127.0.0.1'));
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }

    private function rateLimiterFactory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );
    }
}
