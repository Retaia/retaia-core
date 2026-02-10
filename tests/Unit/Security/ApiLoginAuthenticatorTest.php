<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\ApiLoginAuthenticator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApiLoginAuthenticatorTest extends TestCase
{
    public function testSupportsOnlyPostLoginRoute(): void
    {
        $authenticator = new ApiLoginAuthenticator(new NullLogger(), $this->translator());

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
        $authenticator = new ApiLoginAuthenticator(new NullLogger(), $this->translator());
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
        $authenticator = new ApiLoginAuthenticator(new NullLogger(), $this->translator());
        $request = Request::create('/api/v1/auth/login', Request::METHOD_POST, server: ['CONTENT_TYPE' => 'application/json'], content: '{"email":"","password":""}');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('VALIDATION_FAILED');
        $authenticator->authenticate($request);
    }

    public function testOnAuthenticationSuccessReturnsNormalizedUserPayload(): void
    {
        $authenticator = new ApiLoginAuthenticator(new NullLogger(), $this->translator());
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new User('u-1', 'user@example.test', 'hash', ['ROLE_ADMIN'], true));

        $response = $authenticator->onAuthenticationSuccess(Request::create('/api/v1/auth/login'), $token, 'api');

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame(true, $payload['authenticated']);
        self::assertSame('u-1', $payload['user']['id']);
        self::assertSame('user@example.test', $payload['user']['email']);
    }

    public function testOnAuthenticationSuccessRejectsInvalidUserType(): void
    {
        $authenticator = new ApiLoginAuthenticator(new NullLogger(), $this->translator());
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $response = $authenticator->onAuthenticationSuccess(Request::create('/api/v1/auth/login'), $token, 'api');

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('UNAUTHORIZED', (string) json_decode((string) $response->getContent(), true)['code']);
    }

    public function testOnAuthenticationFailureMapsValidationError(): void
    {
        $authenticator = new ApiLoginAuthenticator(new NullLogger(), $this->translator());

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
        $authenticator = new ApiLoginAuthenticator(new NullLogger(), $this->translator());

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
        $authenticator = new ApiLoginAuthenticator(new NullLogger(), $this->translator());

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
        $authenticator = new ApiLoginAuthenticator(new NullLogger(), $this->translator());

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
        $authenticator = new ApiLoginAuthenticator(new NullLogger(), $this->translator());
        $response = $authenticator->start(Request::create('/api/v1/jobs'));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('UNAUTHORIZED', (string) json_decode((string) $response->getContent(), true)['code']);
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
