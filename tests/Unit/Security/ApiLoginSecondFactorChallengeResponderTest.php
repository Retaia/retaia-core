<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\ApiLoginRequestDataExtractor;
use App\Security\ApiLoginSecondFactorAttemptLimiter;
use App\Security\ApiLoginSecondFactorChallengeResponder;
use App\User\Service\TwoFactorSecretCipher;
use App\User\Service\TwoFactorService;
use App\User\UserTwoFactorStateRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApiLoginSecondFactorChallengeResponderTest extends TestCase
{
    public function testReturnsMfaRequiredWhenEnabledWithoutCode(): void
    {
        [$service] = $this->enabledTwoFactorService('u-1');
        $responder = new ApiLoginSecondFactorChallengeResponder(
            $service,
            $this->limiter(true),
            $this->translator(),
            new ApiLoginRequestDataExtractor()
        );

        $response = $responder->handle(Request::create('/api/v1/auth/login', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: '{}'), new User('u-1', 'user@example.test', 'hash', ['ROLE_USER'], true));

        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('MFA_REQUIRED', json_decode((string) $response->getContent(), true)['code']);
    }

    public function testReturnsInvalidCodeWhenVerificationFails(): void
    {
        [$service] = $this->enabledTwoFactorService('u-1');
        $responder = new ApiLoginSecondFactorChallengeResponder(
            $service,
            $this->limiter(true),
            $this->translator(),
            new ApiLoginRequestDataExtractor()
        );

        $request = Request::create('/api/v1/auth/login', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'], content: '{"otp_code":"123456"}');
        $response = $responder->handle($request, new User('u-1', 'user@example.test', 'hash', ['ROLE_USER'], true));

        self::assertNotNull($response);
        self::assertSame('INVALID_2FA_CODE', json_decode((string) $response->getContent(), true)['code']);
    }

    public function testReturnsNullWhenDisabledOrVerificationPasses(): void
    {
        $disabledService = new TwoFactorService($this->repository($this->connection()), $this->cipher());
        [$enabledService, $secret] = $this->enabledTwoFactorService('u-1');
        $responderDisabled = new ApiLoginSecondFactorChallengeResponder(
            $disabledService,
            $this->limiter(true),
            $this->translator(),
            new ApiLoginRequestDataExtractor()
        );
        $responderEnabled = new ApiLoginSecondFactorChallengeResponder(
            $enabledService,
            $this->limiter(true),
            $this->translator(),
            new ApiLoginRequestDataExtractor()
        );

        self::assertNull($responderDisabled->handle(Request::create('/api/v1/auth/login', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: '{}'), new User('u-1', 'user@example.test', 'hash', ['ROLE_USER'], true)));
        self::assertNull($responderEnabled->handle(Request::create('/api/v1/auth/login', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'], content: '{"otp_code":"'.TOTP::createFromSecret($secret)->now().'"}'), new User('u-1', 'user@example.test', 'hash', ['ROLE_USER'], true)));
    }

    public function testReturnsThrottleResponseWhenLimiterRejects(): void
    {
        [$service] = $this->enabledTwoFactorService('u-1');
        $responder = new ApiLoginSecondFactorChallengeResponder(
            $service,
            $this->limiter(false),
            $this->translator(),
            new ApiLoginRequestDataExtractor()
        );

        $request = Request::create('/api/v1/auth/login', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'], content: '{"otp_code":"123456"}');
        $response = $responder->handle($request, new User('u-1', 'user@example.test', 'hash', ['ROLE_USER'], true));

        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }

    private function limiter(bool $accepted): ApiLoginSecondFactorAttemptLimiter
    {
        $limiter = new ApiLoginSecondFactorAttemptLimiter(
            new RateLimiterFactory([
                'id' => 'test-auth-2fa-challenge-responder',
                'policy' => 'fixed_window',
                'limit' => $accepted ? 10 : 1,
                'interval' => '1 minute',
            ], new InMemoryStorage()),
            $this->translator()
        );

        if (!$accepted) {
            $limiter->consume('u-1', '127.0.0.1');
        }

        return $limiter;
    }

    /**
     * @return array{0: TwoFactorService, 1: string}
     */
    private function enabledTwoFactorService(string $userId): array
    {
        $repository = $this->repository($this->connection());
        $service = new TwoFactorService($repository, $this->cipher());
        $setup = $service->setup($userId, 'user@example.test');
        $secret = (string) ($setup['secret'] ?? '');
        self::assertNotSame('', $secret);
        self::assertTrue($service->enable($userId, TOTP::createFromSecret($secret)->now()));

        return [$service, $secret];
    }

    private function cipher(): TwoFactorSecretCipher
    {
        return new TwoFactorSecretCipher(
            'v1:BR/S2JUbOPhtWvvGSl7mme/p85UkTI3dxqWyj4eBJhs=,v2:WuCdN8gv+LrJgjvD+7nNe1DxvP/pbA4VOMdLhtGa1LU=',
            'v2'
        );
    }

    private function repository(Connection $connection): UserTwoFactorStateRepository
    {
        $connection->executeStatement('CREATE TABLE user_two_factor_state (user_id VARCHAR(32) PRIMARY KEY NOT NULL, enabled BOOLEAN NOT NULL, pending_secret_encrypted CLOB DEFAULT NULL, secret_encrypted CLOB DEFAULT NULL, recovery_code_hashes CLOB NOT NULL, legacy_recovery_code_sha256 CLOB NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');

        return new UserTwoFactorStateRepository($connection);
    }

    private function connection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }
}
