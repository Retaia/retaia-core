<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\AuthClientProvisioningService;
use App\Tests\Support\FixtureUsers;
use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Utils;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AuthProcessGuzzleE2ETest extends WebTestCase
{
    use RecreateDatabaseTrait;

    public function testSpecLoginMeLogoutBearerProcess(): void
    {
        $client = $this->createGuzzleClient();

        $login = $this->requestJson($client, 'POST', '/api/v1/auth/login', [
            'email' => FixtureUsers::ADMIN_EMAIL,
            'password' => FixtureUsers::DEFAULT_PASSWORD,
        ]);

        self::assertSame(200, $login['status']);
        self::assertSame('Bearer', $login['json']['token_type'] ?? null);
        self::assertIsString($login['json']['access_token'] ?? null);
        self::assertSame('', $login['response']->getHeaderLine('Set-Cookie'));

        $token = (string) $login['json']['access_token'];
        self::assertNotSame('', $token);

        $me = $this->requestJson($client, 'GET', '/api/v1/auth/me', null, [
            'Authorization' => 'Bearer '.$token,
        ]);

        self::assertSame(200, $me['status']);
        self::assertSame(FixtureUsers::ADMIN_EMAIL, $me['json']['email'] ?? null);

        $logout = $this->requestJson($client, 'POST', '/api/v1/auth/logout', null, [
            'Authorization' => 'Bearer '.$token,
        ]);

        self::assertSame(200, $logout['status']);

        $meAfterLogout = $this->requestJson($client, 'GET', '/api/v1/auth/me', null, [
            'Authorization' => 'Bearer '.$token,
        ]);

        self::assertSame(401, $meAfterLogout['status']);
        self::assertSame('UNAUTHORIZED', $meAfterLogout['json']['code'] ?? null);
    }

    public function testSpecLoginWithSameClientIdRevokesPreviousToken(): void
    {
        $client = $this->createGuzzleClient();
        $payload = [
            'email' => FixtureUsers::ADMIN_EMAIL,
            'password' => FixtureUsers::DEFAULT_PASSWORD,
            'client_id' => 'interactive-e2e-client',
            'client_kind' => 'AGENT',
        ];

        $firstLogin = $this->requestJson($client, 'POST', '/api/v1/auth/login', $payload);
        self::assertSame(200, $firstLogin['status']);
        $firstToken = (string) ($firstLogin['json']['access_token'] ?? '');
        self::assertNotSame('', $firstToken);

        $secondLogin = $this->requestJson($client, 'POST', '/api/v1/auth/login', $payload);
        self::assertSame(200, $secondLogin['status']);
        $secondToken = (string) ($secondLogin['json']['access_token'] ?? '');
        self::assertNotSame('', $secondToken);
        self::assertNotSame($firstToken, $secondToken);

        $meWithFirstToken = $this->requestJson($client, 'GET', '/api/v1/auth/me', null, [
            'Authorization' => 'Bearer '.$firstToken,
        ]);
        self::assertSame(401, $meWithFirstToken['status']);
        self::assertSame('UNAUTHORIZED', $meWithFirstToken['json']['code'] ?? null);

        $meWithSecondToken = $this->requestJson($client, 'GET', '/api/v1/auth/me', null, [
            'Authorization' => 'Bearer '.$secondToken,
        ]);
        self::assertSame(200, $meWithSecondToken['status']);
        self::assertSame(FixtureUsers::ADMIN_EMAIL, $meWithSecondToken['json']['email'] ?? null);
    }

    public function testSpecAuthEndpointsRejectSessionCookieWithoutBearer(): void
    {
        $client = $this->createGuzzleClient();

        $meWithCookie = $this->requestJson($client, 'GET', '/api/v1/auth/me', null, [
            'Cookie' => 'PHPSESSID=legacy-session-cookie',
        ]);
        self::assertSame(401, $meWithCookie['status']);
        self::assertSame('UNAUTHORIZED', $meWithCookie['json']['code'] ?? null);

        $logoutWithCookie = $this->requestJson($client, 'POST', '/api/v1/auth/logout', null, [
            'Cookie' => 'PHPSESSID=legacy-session-cookie',
        ]);
        self::assertSame(401, $logoutWithCookie['status']);
        self::assertSame('UNAUTHORIZED', $logoutWithCookie['json']['code'] ?? null);
    }

    public function testSpecClientTokenRejectsUiRust(): void
    {
        $client = $this->createGuzzleClient();

        $response = $this->requestJson($client, 'POST', '/api/v1/auth/clients/token', [
            'client_id' => 'agent-default',
            'client_kind' => 'UI_RUST',
            'secret_key' => 'agent-secret',
        ]);

        self::assertSame(403, $response['status']);
        self::assertSame('FORBIDDEN_ACTOR', $response['json']['code'] ?? null);
    }

    public function testSpecClientTokenMintRevokesPreviousTokenForSameClient(): void
    {
        $client = $this->createGuzzleClient();
        $credentials = $this->provisionTechnicalClient('AGENT');
        $payload = [
            'client_id' => $credentials['client_id'],
            'client_kind' => 'AGENT',
            'secret_key' => $credentials['secret_key'],
        ];

        $firstMint = $this->requestJson($client, 'POST', '/api/v1/auth/clients/token', $payload);
        self::assertSame(200, $firstMint['status']);
        $firstToken = (string) ($firstMint['json']['access_token'] ?? '');
        self::assertNotSame('', $firstToken);

        $secondMint = $this->requestJson($client, 'POST', '/api/v1/auth/clients/token', $payload);
        self::assertSame(200, $secondMint['status']);
        $secondToken = (string) ($secondMint['json']['access_token'] ?? '');
        self::assertNotSame('', $secondToken);
        self::assertNotSame($firstToken, $secondToken);

        $policyWithFirstToken = $this->requestJson($client, 'GET', '/api/v1/app/policy', null, [
            'Authorization' => 'Bearer '.$firstToken,
        ]);
        self::assertSame(401, $policyWithFirstToken['status']);
        self::assertSame('UNAUTHORIZED', $policyWithFirstToken['json']['code'] ?? null);

        $policyWithSecondToken = $this->requestJson($client, 'GET', '/api/v1/app/policy', null, [
            'Authorization' => 'Bearer '.$secondToken,
        ]);
        self::assertSame(200, $policyWithSecondToken['status']);
    }

    public function testSpecDeviceFlowIsStatusDrivenAndCancelable(): void
    {
        $client = $this->createGuzzleClient();

        $start = $this->requestJson($client, 'POST', '/api/v1/auth/clients/device/start', [
            'client_kind' => 'AGENT',
        ]);

        self::assertSame(200, $start['status']);
        self::assertIsString($start['json']['device_code'] ?? null);
        self::assertIsString($start['json']['user_code'] ?? null);
        self::assertIsString($start['json']['verification_uri'] ?? null);
        self::assertIsString($start['json']['verification_uri_complete'] ?? null);

        $deviceCode = (string) $start['json']['device_code'];

        $pollPending = $this->requestJson($client, 'POST', '/api/v1/auth/clients/device/poll', [
            'device_code' => $deviceCode,
        ]);

        self::assertSame(200, $pollPending['status']);
        self::assertSame('PENDING', $pollPending['json']['status'] ?? null);

        $cancel = $this->requestJson($client, 'POST', '/api/v1/auth/clients/device/cancel', [
            'device_code' => $deviceCode,
        ]);

        self::assertSame(200, $cancel['status']);
        self::assertSame(true, $cancel['json']['canceled'] ?? null);

        $pollDenied = null;
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $candidate = $this->requestJson($client, 'POST', '/api/v1/auth/clients/device/poll', [
                'device_code' => $deviceCode,
            ]);

            if ($candidate['status'] === 200) {
                $pollDenied = $candidate;
                break;
            }

            if ($candidate['status'] !== 429 || ($candidate['json']['code'] ?? null) !== 'SLOW_DOWN') {
                $pollDenied = $candidate;
                break;
            }

            $retryInSeconds = max(1, (int) ($candidate['json']['retry_in_seconds'] ?? 1));
            usleep($retryInSeconds * 1_000_000);
        }

        self::assertIsArray($pollDenied);
        self::assertSame(200, $pollDenied['status']);
        self::assertSame('DENIED', $pollDenied['json']['status'] ?? null);
    }

    public function testSpecDevicePollInvalidCodeReturns400(): void
    {
        $client = $this->createGuzzleClient();

        $pollInvalid = $this->requestJson($client, 'POST', '/api/v1/auth/clients/device/poll', [
            'device_code' => 'invalid',
        ]);

        self::assertSame(400, $pollInvalid['status']);
        self::assertSame('INVALID_DEVICE_CODE', $pollInvalid['json']['code'] ?? null);
    }

    public function testSpecLostPasswordFlowPersistsAndConsumesResetTokenInDb(): void
    {
        $client = $this->createGuzzleClient();

        $requestReset = $this->requestJson($client, 'POST', '/api/v1/auth/lost-password/request', [
            'email' => FixtureUsers::ADMIN_EMAIL,
        ]);
        self::assertSame(202, $requestReset['status']);
        $resetToken = (string) ($requestReset['json']['reset_token'] ?? '');
        self::assertNotSame('', $resetToken);

        $connection = static::getContainer()->get(Connection::class);
        $tokenHash = hash('sha256', $resetToken);
        $storedCount = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM password_reset_token WHERE token_hash = :tokenHash',
            ['tokenHash' => $tokenHash]
        );
        self::assertSame(1, $storedCount);

        $newPassword = 'New-password1!';
        $reset = $this->requestJson($client, 'POST', '/api/v1/auth/lost-password/reset', [
            'token' => $resetToken,
            'new_password' => $newPassword,
        ]);
        self::assertSame(200, $reset['status']);

        $remainingCount = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM password_reset_token WHERE token_hash = :tokenHash',
            ['tokenHash' => $tokenHash]
        );
        self::assertSame(0, $remainingCount);

        $loginWithNewPassword = $this->requestJson($client, 'POST', '/api/v1/auth/login', [
            'email' => FixtureUsers::ADMIN_EMAIL,
            'password' => $newPassword,
        ]);
        self::assertSame(200, $loginWithNewPassword['status']);
        self::assertSame('Bearer', $loginWithNewPassword['json']['token_type'] ?? null);
    }

    public function testSpecVerifyEmailConfirmUpdatesUserVerificationFlagInDb(): void
    {
        $client = $this->createGuzzleClient();
        $email = sprintf('e2e-verify-%s@retaia.local', bin2hex(random_bytes(4)));
        $this->insertUser($email, 'change-me', ['ROLE_USER'], false);

        $connection = static::getContainer()->get(Connection::class);
        $before = (int) $connection->fetchOne(
            'SELECT email_verified FROM app_user WHERE email = :email',
            ['email' => $email]
        );
        self::assertSame(0, $before);

        $requestVerification = $this->requestJson($client, 'POST', '/api/v1/auth/verify-email/request', [
            'email' => $email,
        ]);
        self::assertSame(202, $requestVerification['status']);
        $verificationToken = (string) ($requestVerification['json']['verification_token'] ?? '');
        self::assertNotSame('', $verificationToken);

        $confirm = $this->requestJson($client, 'POST', '/api/v1/auth/verify-email/confirm', [
            'token' => $verificationToken,
        ]);
        self::assertSame(200, $confirm['status']);
        self::assertSame(true, $confirm['json']['email_verified'] ?? null);

        $after = (int) $connection->fetchOne(
            'SELECT email_verified FROM app_user WHERE email = :email',
            ['email' => $email]
        );
        self::assertSame(1, $after);
    }

    public function testSpecVerifyEmailAdminConfirmRequiresBearerAndUpdatesDb(): void
    {
        $client = $this->createGuzzleClient();
        $email = sprintf('e2e-admin-verify-%s@retaia.local', bin2hex(random_bytes(4)));
        $this->insertUser($email, 'change-me', ['ROLE_USER'], false);

        $unauthorized = $this->requestJson($client, 'POST', '/api/v1/auth/verify-email/admin-confirm', [
            'email' => $email,
        ]);
        self::assertSame(401, $unauthorized['status']);
        self::assertSame('UNAUTHORIZED', $unauthorized['json']['code'] ?? null);

        $adminToken = $this->loginAdminAndGetBearerToken($client);
        $authorized = $this->requestJson($client, 'POST', '/api/v1/auth/verify-email/admin-confirm', [
            'email' => $email,
        ], [
            'Authorization' => 'Bearer '.$adminToken,
        ]);
        self::assertSame(200, $authorized['status']);
        self::assertSame(true, $authorized['json']['email_verified'] ?? null);

        $connection = static::getContainer()->get(Connection::class);
        $verified = (int) $connection->fetchOne(
            'SELECT email_verified FROM app_user WHERE email = :email',
            ['email' => $email]
        );
        self::assertSame(1, $verified);
    }

    public function testSpecRevokeClientTokenRequiresBearerAndInvalidatesClientToken(): void
    {
        $client = $this->createGuzzleClient();
        $credentials = $this->provisionTechnicalClient('AGENT');

        $mint = $this->requestJson($client, 'POST', '/api/v1/auth/clients/token', [
            'client_id' => $credentials['client_id'],
            'client_kind' => 'AGENT',
            'secret_key' => $credentials['secret_key'],
        ]);
        self::assertSame(200, $mint['status']);
        $clientToken = (string) ($mint['json']['access_token'] ?? '');
        self::assertNotSame('', $clientToken);

        $unauthorized = $this->requestJson(
            $client,
            'POST',
            sprintf('/api/v1/auth/clients/%s/revoke-token', $credentials['client_id'])
        );
        self::assertSame(401, $unauthorized['status']);
        self::assertSame('UNAUTHORIZED', $unauthorized['json']['code'] ?? null);

        $adminToken = $this->loginAdminAndGetBearerToken($client);
        $authorized = $this->requestJson(
            $client,
            'POST',
            sprintf('/api/v1/auth/clients/%s/revoke-token', $credentials['client_id']),
            null,
            ['Authorization' => 'Bearer '.$adminToken]
        );
        self::assertSame(200, $authorized['status']);
        self::assertSame(true, $authorized['json']['revoked'] ?? null);

        $policyWithRevokedToken = $this->requestJson($client, 'GET', '/api/v1/app/policy', null, [
            'Authorization' => 'Bearer '.$clientToken,
        ]);
        self::assertSame(401, $policyWithRevokedToken['status']);
        self::assertSame('UNAUTHORIZED', $policyWithRevokedToken['json']['code'] ?? null);
    }

    public function testSpecRotateClientSecretReturnsNewOneAndRevokesPreviousCredentials(): void
    {
        $client = $this->createGuzzleClient();
        $credentials = $this->provisionTechnicalClient('AGENT');
        $adminToken = $this->loginAdminAndGetBearerToken($client);

        $rotate = $this->requestJson(
            $client,
            'POST',
            sprintf('/api/v1/auth/clients/%s/rotate-secret', $credentials['client_id']),
            null,
            ['Authorization' => 'Bearer '.$adminToken]
        );
        self::assertSame(200, $rotate['status']);
        self::assertSame(true, $rotate['json']['rotated'] ?? null);
        $newSecret = (string) ($rotate['json']['secret_key'] ?? '');
        self::assertNotSame('', $newSecret);
        self::assertNotSame($credentials['secret_key'], $newSecret);

        $mintWithOldSecret = $this->requestJson($client, 'POST', '/api/v1/auth/clients/token', [
            'client_id' => $credentials['client_id'],
            'client_kind' => 'AGENT',
            'secret_key' => $credentials['secret_key'],
        ]);
        self::assertSame(401, $mintWithOldSecret['status']);
        self::assertSame('UNAUTHORIZED', $mintWithOldSecret['json']['code'] ?? null);

        $mintWithNewSecret = $this->requestJson($client, 'POST', '/api/v1/auth/clients/token', [
            'client_id' => $credentials['client_id'],
            'client_kind' => 'AGENT',
            'secret_key' => $newSecret,
        ]);
        self::assertSame(200, $mintWithNewSecret['status']);
        self::assertSame('Bearer', $mintWithNewSecret['json']['token_type'] ?? null);
    }

    private function createGuzzleClient(): Client
    {
        static::bootKernel();

        /** @var HttpKernelInterface $httpKernel */
        $httpKernel = static::getContainer()->get(HttpKernelInterface::class);

        $handler = static function (RequestInterface $request) use ($httpKernel) {
            $uri = $request->getUri();
            $server = [
                'REQUEST_METHOD' => strtoupper($request->getMethod()),
                'REQUEST_URI' => $uri->getPath().($uri->getQuery() !== '' ? '?'.$uri->getQuery() : ''),
                'QUERY_STRING' => $uri->getQuery(),
                'SERVER_NAME' => $uri->getHost() !== '' ? $uri->getHost() : 'localhost',
                'SERVER_PORT' => $uri->getPort() ?? 80,
                'HTTPS' => 'off',
            ];

            foreach ($request->getHeaders() as $name => $values) {
                $normalized = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
                $server[$normalized] = implode(', ', $values);
            }

            $symfonyRequest = Request::create(
                $uri->getPath().($uri->getQuery() !== '' ? '?'.$uri->getQuery() : ''),
                strtoupper($request->getMethod()),
                [],
                [],
                [],
                $server,
                (string) $request->getBody(),
            );

            $symfonyResponse = $httpKernel->handle($symfonyRequest, HttpKernelInterface::MAIN_REQUEST, true);

            $response = new Psr7Response(
                $symfonyResponse->getStatusCode(),
                $symfonyResponse->headers->allPreserveCaseWithoutCookies(),
                Utils::streamFor($symfonyResponse->getContent()),
            );

            return Create::promiseFor($response);
        };

        return new Client([
            'base_uri' => 'http://localhost',
            'handler' => HandlerStack::create($handler),
            'http_errors' => false,
        ]);
    }

    /**
     * @return array{client_id: string, secret_key: string}
     */
    private function provisionTechnicalClient(string $clientKind): array
    {
        /** @var AuthClientProvisioningService $provisioning */
        $provisioning = static::getContainer()->get(AuthClientProvisioningService::class);
        $credentials = $provisioning->provisionClient($clientKind);
        self::assertIsArray($credentials);
        self::assertIsString($credentials['client_id'] ?? null);
        self::assertIsString($credentials['secret_key'] ?? null);

        return $credentials;
    }

    private function loginAdminAndGetBearerToken(Client $client): string
    {
        $login = $this->requestJson($client, 'POST', '/api/v1/auth/login', [
            'email' => FixtureUsers::ADMIN_EMAIL,
            'password' => FixtureUsers::DEFAULT_PASSWORD,
        ]);
        self::assertSame(200, $login['status']);

        $token = (string) ($login['json']['access_token'] ?? '');
        self::assertNotSame('', $token);

        return $token;
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @param array<string, string> $headers
     *
     * @return array{status: int, json: array<string, mixed>|null, response: ResponseInterface}
     */
    private function requestJson(Client $client, string $method, string $path, ?array $jsonBody = null, array $headers = []): array
    {
        $options = [
            'headers' => array_merge(['Accept' => 'application/json'], $headers),
        ];

        if ($jsonBody !== null) {
            $options['json'] = $jsonBody;
        }

        $response = $client->request($method, $path, $options);
        $contents = (string) $response->getBody();
        $decoded = json_decode($contents, true);

        return [
            'status' => $response->getStatusCode(),
            'json' => is_array($decoded) ? $decoded : null,
            'response' => $response,
        ];
    }

    /**
     * @param array<int, string> $roles
     */
    private function insertUser(string $email, string $plainPassword, array $roles, bool $emailVerified): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('app_user', [
            'id' => substr(bin2hex(random_bytes(16)), 0, 32),
            'email' => mb_strtolower(trim($email)),
            'password_hash' => password_hash($plainPassword, PASSWORD_BCRYPT),
            'roles' => json_encode(array_values($roles), JSON_THROW_ON_ERROR),
            'email_verified' => $emailVerified ? 1 : 0,
        ]);
    }
}
