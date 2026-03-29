<?php

namespace App\Tests\Unit\Security;

use App\Auth\ClientAccessTokenResolver;
use App\Auth\TechnicalAccessTokenRecord;
use App\Auth\TechnicalAccessTokenRepository;
use App\Auth\UserAccessTokenService;
use App\Auth\UserAccessJwtService;
use App\Auth\UserAuthSessionRepository;
use App\Auth\UserAuthSessionService;
use App\Tests\Support\UserAuthSessionEntityManagerTrait;
use App\Domain\AuthClient\ClientKind;
use App\Entity\User;
use App\Security\ApiBearerAuthenticator;
use App\Security\ApiClientPrincipal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApiBearerAuthenticatorTest extends TestCase
{
    use UserAuthSessionEntityManagerTrait;

    public function testSupportsSkipsPublicApiPathsAndNonApiPaths(): void
    {
        $authenticator = $this->authenticator();

        self::assertFalse($authenticator->supports(Request::create('/health')));
        self::assertFalse($authenticator->supports($this->request('/api/v1/docs')));
        self::assertFalse($authenticator->supports($this->request('/api/v1/auth/login')));
        self::assertFalse($authenticator->supports($this->request('/api/v1/assets')));
        self::assertTrue($authenticator->supports($this->request('/api/v1/assets', 'Bearer token')));
        self::assertTrue($authenticator->supports($this->request('/device/authorize', 'Bearer token')));
    }

    public function testAuthenticateBuildsPassportForUserToken(): void
    {
        $connection = $this->connection();
        $repository = new UserAuthSessionRepository($this->userAuthSessionEntityManager());
        $userTokens = new UserAccessTokenService(new UserAuthSessionService($repository), new UserAccessJwtService('test-secret', 3600));
        $authenticator = $this->authenticator($userTokens, new ClientAccessTokenResolver(new TechnicalAccessTokenRepository($connection)));
        $user = new User('user-1', 'user@example.test', 'hash');

        $issued = $userTokens->issue($user, 'ui-web', ClientKind::UI_WEB);
        $passport = $authenticator->authenticate($this->request('/api/v1/assets', 'Bearer '.$issued['access_token']));

        self::assertTrue($passport->hasBadge(UserBadge::class));
        $badge = $passport->getBadge(UserBadge::class);
        self::assertInstanceOf(UserBadge::class, $badge);
        self::assertSame('user@example.test', $badge->getUserIdentifier());
        self::assertNull($badge->getUserLoader());
    }

    public function testAuthenticateBuildsPassportForClientToken(): void
    {
        $connection = $this->connection();
        $tokenRepository = new TechnicalAccessTokenRepository($connection);
        $tokenRepository->save(new TechnicalAccessTokenRecord('agent-client', 'client-token', ClientKind::AGENT, 1_700_000_000));

        $authenticator = $this->authenticator(
            new UserAccessTokenService(
                new UserAuthSessionService(new UserAuthSessionRepository($this->userAuthSessionEntityManager())),
                new UserAccessJwtService('test-secret', 3600)
            ),
            new ClientAccessTokenResolver($tokenRepository)
        );

        $passport = $authenticator->authenticate($this->request('/api/v1/jobs/claim', 'Bearer client-token'));
        $badge = $passport->getBadge(UserBadge::class);

        self::assertInstanceOf(UserBadge::class, $badge);
        self::assertSame('client:agent-client', $badge->getUserIdentifier());
        $principal = $badge->getUser();
        self::assertInstanceOf(ApiClientPrincipal::class, $principal);
        self::assertSame('client:agent-client', $principal->getUserIdentifier());
        self::assertSame(['ROLE_AGENT'], $principal->getRoles());
    }

    public function testAuthenticateRejectsBlankAndUnknownTokens(): void
    {
        $authenticator = $this->authenticator();

        try {
            $authenticator->authenticate($this->request('/api/v1/assets', 'Bearer '));
            self::fail('Expected blank bearer token to be rejected.');
        } catch (CustomUserMessageAuthenticationException $exception) {
            self::assertSame('UNAUTHORIZED', $exception->getMessageKey());
        }

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('UNAUTHORIZED');
        $authenticator->authenticate($this->request('/api/v1/assets', 'Bearer unknown-token'));
    }

    public function testAuthenticationFailureReturnsUnauthorizedPayload(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->with('auth.error.authentication_required')->willReturn('authentication required');
        $authenticator = $this->authenticator(translator: $translator);

        $response = $authenticator->onAuthenticationFailure(
            $this->request('/api/v1/assets', 'Bearer invalid'),
            new CustomUserMessageAuthenticationException('UNAUTHORIZED')
        );

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(
            ['code' => 'UNAUTHORIZED', 'message' => 'authentication required'],
            json_decode((string) $response->getContent(), true)
        );
    }

    private function authenticator(
        ?UserAccessTokenService $userTokens = null,
        ?ClientAccessTokenResolver $clientResolver = null,
        ?TranslatorInterface $translator = null,
    ): ApiBearerAuthenticator {
        $connection = $this->connection();

        return new ApiBearerAuthenticator(
            $userTokens ?? new UserAccessTokenService(
                new UserAuthSessionService(new UserAuthSessionRepository($this->userAuthSessionEntityManager())),
                new UserAccessJwtService('test-secret', 3600)
            ),
            $clientResolver ?? new ClientAccessTokenResolver(new TechnicalAccessTokenRepository($connection)),
            $translator ?? $this->createStub(TranslatorInterface::class),
        );
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE user_auth_session (session_id VARCHAR(32) PRIMARY KEY NOT NULL, access_token CLOB NOT NULL, refresh_token VARCHAR(255) NOT NULL, access_expires_at INTEGER NOT NULL, refresh_expires_at INTEGER NOT NULL, user_id VARCHAR(32) NOT NULL, email VARCHAR(180) NOT NULL, client_id VARCHAR(64) NOT NULL, client_kind VARCHAR(32) NOT NULL, created_at INTEGER NOT NULL, last_used_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_user_auth_session_refresh_token ON user_auth_session (refresh_token)');
        $connection->executeStatement('CREATE INDEX idx_user_auth_session_user_id ON user_auth_session (user_id)');
        $connection->executeStatement('CREATE TABLE auth_client_access_token (client_id VARCHAR(64) PRIMARY KEY NOT NULL, access_token CLOB NOT NULL, client_kind VARCHAR(32) NOT NULL, issued_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_auth_client_access_token_token ON auth_client_access_token (access_token)');

        return $connection;
    }

    private function request(string $path, ?string $authorization = null): Request
    {
        $request = Request::create($path);
        if ($authorization !== null) {
            $request->headers->set('Authorization', $authorization);
        }

        return $request;
    }
}
