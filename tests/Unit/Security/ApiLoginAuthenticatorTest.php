<?php

namespace App\Tests\Unit\Security;

use App\Tests\Support\TranslatorStubTrait;
use App\Auth\UserAccessTokenService;
use App\Auth\UserAccessJwtService;
use App\Auth\UserAuthSessionRepository;
use App\Auth\UserAuthSessionService;
use App\Entity\User;
use App\Tests\Support\UserAuthSessionEntityManagerTrait;
use App\Security\ApiLoginAuthenticator;
use App\User\Service\TwoFactorSecretCipher;
use App\User\Service\TwoFactorService;
use App\User\UserTwoFactorStateRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;

final class ApiLoginAuthenticatorTest extends TestCase
{
    use TranslatorStubTrait;
    use UserAuthSessionEntityManagerTrait;

    public function testSupportsOnlyPostLoginRoute(): void
    {
        $authenticator = $this->authenticator();

        $loginPost = Request::create('/api/v1/auth/login', Request::METHOD_POST);
        $loginPost->attributes->set('_route', 'api_auth_login');

        $loginGet = Request::create('/api/v1/auth/login', Request::METHOD_GET);
        $loginGet->attributes->set('_route', 'api_auth_login');

        $otherPost = Request::create('/api/v1/auth/other', Request::METHOD_POST);
        $otherPost->attributes->set('_route', 'api_auth_other');

        self::assertTrue((bool) $authenticator->supports($loginPost));
        self::assertFalse((bool) $authenticator->supports($loginGet));
        self::assertFalse((bool) $authenticator->supports($otherPost));
    }

    public function testAuthenticateBuildsPassportForValidCredentials(): void
    {
        $authenticator = $this->authenticator();
        $request = Request::create('/api/v1/auth/login', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: '{"email":"admin@retaia.local","password":"change-me"}');

        $passport = $authenticator->authenticate($request);

        $userBadge = $passport->getBadge(UserBadge::class);
        $passwordCredentials = $passport->getBadge(PasswordCredentials::class);

        self::assertInstanceOf(UserBadge::class, $userBadge);
        self::assertInstanceOf(PasswordCredentials::class, $passwordCredentials);
        self::assertSame('admin@retaia.local', $userBadge->getUserIdentifier());
    }

    public function testAuthenticateRejectsMissingCredentials(): void
    {
        $authenticator = $this->authenticator();
        $request = Request::create('/api/v1/auth/login', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: '{"email":"","password":""}');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('VALIDATION_FAILED');
        $authenticator->authenticate($request);
    }

    public function testOnAuthenticationSuccessReturnsTokenPayload(): void
    {
        $authenticator = $this->authenticator();
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new User('u-1', 'user@example.test', 'hash', ['ROLE_ADMIN'], true));

        $response = $authenticator->onAuthenticationSuccess(Request::create('/api/v1/auth/login'), $token, 'api');

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertArrayNotHasKey('authenticated', $payload);
        self::assertArrayNotHasKey('user', $payload);
        self::assertIsString($payload['access_token'] ?? null);
        self::assertIsString($payload['refresh_token'] ?? null);
        self::assertSame(3600, $payload['expires_in'] ?? null);
    }

    public function testOnAuthenticationSuccessAcceptsAgentClientKind(): void
    {
        $authenticator = $this->authenticator();
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new User('u-1', 'user@example.test', 'hash', ['ROLE_USER'], true));

        $request = Request::create(
            '/api/v1/auth/login',
            Request::METHOD_POST,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"email":"user@example.test","password":"change-me","client_kind":"AGENT"}'
        );

        $response = $authenticator->onAuthenticationSuccess($request, $token, 'api');

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame('AGENT', $payload['client_kind'] ?? null);
    }

    public function testOnAuthenticationSuccessRejectsInvalidUserType(): void
    {
        $authenticator = $this->authenticator();
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $response = $authenticator->onAuthenticationSuccess(Request::create('/api/v1/auth/login'), $token, 'api');

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('UNAUTHORIZED', (string) json_decode((string) $response->getContent(), true)['code']);
    }

    public function testOnAuthenticationFailureMapsValidationError(): void
    {
        $authenticator = $this->authenticator();

        $response = $authenticator->onAuthenticationFailure(
            Request::create('/api/v1/auth/login', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: '{"email":"x@y.test"}'),
            new CustomUserMessageAuthenticationException('VALIDATION_FAILED')
        );

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', (string) json_decode((string) $response->getContent(), true)['code']);
    }

    public function testOnAuthenticationFailureMapsEmailVerificationError(): void
    {
        $authenticator = $this->authenticator();

        $response = $authenticator->onAuthenticationFailure(
            Request::create('/api/v1/auth/login', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: '{"email":"x@y.test"}'),
            new CustomUserMessageAuthenticationException('EMAIL_NOT_VERIFIED')
        );

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('EMAIL_NOT_VERIFIED', (string) json_decode((string) $response->getContent(), true)['code']);
    }

    public function testOnAuthenticationFailureMapsThrottleError(): void
    {
        $authenticator = $this->authenticator();

        $response = $authenticator->onAuthenticationFailure(
            Request::create('/api/v1/auth/login', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: '{"email":"x@y.test"}'),
            new TooManyLoginAttemptsAuthenticationException(3)
        );

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame('TOO_MANY_ATTEMPTS', $payload['code']);
        self::assertSame(3, $payload['retry_in_minutes']);
    }

    public function testOnAuthenticationFailureMapsUnauthorizedError(): void
    {
        $authenticator = $this->authenticator();

        $response = $authenticator->onAuthenticationFailure(
            Request::create('/api/v1/auth/login', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: '{"email":"x@y.test"}'),
            new AuthenticationException('invalid')
        );

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('UNAUTHORIZED', (string) json_decode((string) $response->getContent(), true)['code']);
    }

    public function testStartReturnsAuthenticationRequiredResponse(): void
    {
        $authenticator = $this->authenticator();
        $response = $authenticator->start(Request::create('/api/v1/jobs'));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('UNAUTHORIZED', (string) json_decode((string) $response->getContent(), true)['code']);
    }


    private function authenticator(): ApiLoginAuthenticator
    {
        $twoFactor = new TwoFactorService(
            new UserTwoFactorStateRepository($this->connection()),
            new TwoFactorSecretCipher(
                'v1:BR/S2JUbOPhtWvvGSl7mme/p85UkTI3dxqWyj4eBJhs=,v2:WuCdN8gv+LrJgjvD+7nNe1DxvP/pbA4VOMdLhtGa1LU=',
                'v2'
            )
        );
        $repository = new UserAuthSessionRepository($this->userAuthSessionEntityManager());
        $userTokens = new UserAccessTokenService(
            new UserAuthSessionService($repository),
            new UserAccessJwtService('test-secret', 3600)
        );

        return new ApiLoginAuthenticator(
            new NullLogger(),
            $this->translatorStub(),
            $twoFactor,
            $userTokens,
            new RateLimiterFactory([
                'id' => 'test-auth-2fa-challenge',
                'policy' => 'fixed_window',
                'limit' => 50,
                'interval' => '1 minute',
            ], new InMemoryStorage())
        );
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE user_auth_session (session_id VARCHAR(32) PRIMARY KEY NOT NULL, access_token CLOB NOT NULL, refresh_token VARCHAR(255) NOT NULL, access_expires_at INTEGER NOT NULL, refresh_expires_at INTEGER NOT NULL, user_id VARCHAR(32) NOT NULL, email VARCHAR(180) NOT NULL, client_id VARCHAR(64) NOT NULL, client_kind VARCHAR(32) NOT NULL, created_at INTEGER NOT NULL, last_used_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_user_auth_session_refresh_token ON user_auth_session (refresh_token)');
        $connection->executeStatement('CREATE INDEX idx_user_auth_session_user_id ON user_auth_session (user_id)');
        $connection->executeStatement('CREATE TABLE user_two_factor_state (user_id VARCHAR(32) PRIMARY KEY NOT NULL, enabled BOOLEAN NOT NULL, pending_secret_encrypted CLOB DEFAULT NULL, secret_encrypted CLOB DEFAULT NULL, recovery_code_hashes CLOB NOT NULL, legacy_recovery_code_sha256 CLOB NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');

        return $connection;
    }
}
