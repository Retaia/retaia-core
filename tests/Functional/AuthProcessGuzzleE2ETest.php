<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\AuthClientProvisioningService;
use App\Tests\Functional\Support\AuthDeviceFlowCacheTrait;
use App\Tests\Support\FixtureUsers;
use App\Tests\Support\FunctionalSchemaTrait;
use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Utils;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use OTPHP\TOTP;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AuthProcessGuzzleE2ETest extends WebTestCase
{
    use RecreateDatabaseTrait;
    use AuthDeviceFlowCacheTrait;
    use FunctionalSchemaTrait;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();
        static::bootKernel();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $this->ensureAuthClientTables($connection);
        $this->ensureUserAuthSessionTable($connection);
        $this->ensureUserTwoFactorStateTable($connection);
    }

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

        $token = (string) ($login['json']['access_token'] ?? '');
        self::assertNotSame('', $token);

        $me = $this->requestJson($client, 'GET', '/api/v1/auth/me', null, [
            'Authorization' => 'Bearer '.$token,
        ]);

        self::assertSame(200, $me['status']);
        self::assertSame(FixtureUsers::ADMIN_EMAIL, $me['json']['email'] ?? null);
        self::assertIsString($me['json']['uuid'] ?? null);
        self::assertArrayHasKey('display_name', $me['json']);
        self::assertSame(true, $me['json']['email_verified'] ?? null);
        self::assertSame(false, $me['json']['mfa_enabled'] ?? null);

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

    public function testSpecAppPolicyRequiresBearerAndSupportsUserAndTechnicalTokens(): void
    {
        $client = $this->createGuzzleClient();

        $unauthorized = $this->requestJson($client, 'GET', '/api/v1/app/policy');
        self::assertSame(401, $unauthorized['status']);
        self::assertSame('UNAUTHORIZED', $unauthorized['json']['code'] ?? null);

        $adminToken = $this->loginAdminAndGetBearerToken($client);
        $policyForUser = $this->requestJson($client, 'GET', '/api/v1/app/policy', null, [
            'Authorization' => 'Bearer '.$adminToken,
        ]);
        self::assertSame(200, $policyForUser['status']);
        self::assertIsArray($policyForUser['json']['server_policy'] ?? null);
        self::assertIsArray($policyForUser['json']['server_policy']['feature_flags'] ?? null);

        $credentials = $this->provisionTechnicalClient('AGENT');
        $mint = $this->requestJson($client, 'POST', '/api/v1/auth/clients/token', [
            'client_id' => $credentials['client_id'],
            'client_kind' => 'AGENT',
            'secret_key' => $credentials['secret_key'],
        ]);
        self::assertSame(200, $mint['status']);
        $technicalToken = (string) ($mint['json']['access_token'] ?? '');
        self::assertNotSame('', $technicalToken);

        $policyForTechnicalClient = $this->requestJson($client, 'GET', '/api/v1/app/policy', null, [
            'Authorization' => 'Bearer '.$technicalToken,
        ]);
        self::assertSame(200, $policyForTechnicalClient['status']);
        self::assertIsArray($policyForTechnicalClient['json']['server_policy'] ?? null);
        self::assertIsArray($policyForTechnicalClient['json']['server_policy']['feature_flags'] ?? null);
    }

    public function testSpecDevicePollValidationApprovedStatusAndOneShotSecret(): void
    {
        $client = $this->createGuzzleClient();

        $pollMissingCode = $this->requestJson($client, 'POST', '/api/v1/auth/clients/device/poll', []);
        self::assertSame(422, $pollMissingCode['status']);
        self::assertSame('VALIDATION_FAILED', $pollMissingCode['json']['code'] ?? null);

        $start = $this->requestJson($client, 'POST', '/api/v1/auth/clients/device/start', [
            'client_kind' => 'AGENT',
        ]);
        self::assertSame(200, $start['status']);
        $deviceCode = (string) ($start['json']['device_code'] ?? '');
        $userCode = (string) ($start['json']['user_code'] ?? '');
        self::assertNotSame('', $deviceCode);
        self::assertNotSame('', $userCode);

        $firstPoll = $this->requestJson($client, 'POST', '/api/v1/auth/clients/device/poll', [
            'device_code' => $deviceCode,
        ]);
        self::assertSame(200, $firstPoll['status']);
        self::assertSame('PENDING', $firstPoll['json']['status'] ?? null);

        $adminToken = $this->loginAdminAndGetBearerToken($client);
        $approve = $this->requestJson($client, 'POST', '/device', [
            'user_code' => $userCode,
        ], [
            'Authorization' => 'Bearer '.$adminToken,
        ]);
        self::assertSame(200, $approve['status']);
        self::assertSame(true, $approve['json']['approved'] ?? null);

        $this->forceDeviceFlowLastPolledAt($deviceCode, time() - 30);

        $approvedPoll = $this->requestJson($client, 'POST', '/api/v1/auth/clients/device/poll', [
            'device_code' => $deviceCode,
        ]);
        self::assertSame(200, $approvedPoll['status']);
        self::assertSame('APPROVED', $approvedPoll['json']['status'] ?? null);
        self::assertIsString($approvedPoll['json']['client_id'] ?? null);
        self::assertNotSame('', (string) ($approvedPoll['json']['client_id'] ?? ''));
        self::assertSame('AGENT', $approvedPoll['json']['client_kind'] ?? null);
        self::assertIsString($approvedPoll['json']['secret_key'] ?? null);
        self::assertNotSame('', (string) ($approvedPoll['json']['secret_key'] ?? ''));

        $pollAfterApprovedConsumption = $this->requestJson($client, 'POST', '/api/v1/auth/clients/device/poll', [
            'device_code' => $deviceCode,
        ]);
        self::assertSame(400, $pollAfterApprovedConsumption['status']);
        self::assertSame('INVALID_DEVICE_CODE', $pollAfterApprovedConsumption['json']['code'] ?? null);
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

    public function testSpecLoginWithEnabledTwoFactorRequiresValidOtp(): void
    {
        $client = $this->createGuzzleClient();
        $adminToken = $this->loginAdminAndGetBearerToken($client);
        $secret = '';
        $loginBearer = '';

        try {
            $setup = $this->requestJson($client, 'POST', '/api/v1/auth/2fa/setup', null, [
                'Authorization' => 'Bearer '.$adminToken,
            ]);
            self::assertSame(200, $setup['status']);
            $secret = (string) ($setup['json']['secret'] ?? '');
            self::assertNotSame('', $secret);
            self::assertIsString($setup['json']['otpauth_uri'] ?? null);

            $enable = $this->requestJson($client, 'POST', '/api/v1/auth/2fa/enable', [
                'otp_code' => $this->generateOtpCode($secret),
            ], [
                'Authorization' => 'Bearer '.$adminToken,
            ]);
            self::assertSame(200, $enable['status']);
            self::assertSame(true, $enable['json']['mfa_enabled'] ?? null);

            $loginWithoutOtp = $this->requestJson($client, 'POST', '/api/v1/auth/login', [
                'email' => FixtureUsers::ADMIN_EMAIL,
                'password' => FixtureUsers::DEFAULT_PASSWORD,
            ]);
            self::assertSame(401, $loginWithoutOtp['status']);
            self::assertSame('MFA_REQUIRED', $loginWithoutOtp['json']['code'] ?? null);

            $loginWithInvalidOtp = $this->requestJson($client, 'POST', '/api/v1/auth/login', [
                'email' => FixtureUsers::ADMIN_EMAIL,
                'password' => FixtureUsers::DEFAULT_PASSWORD,
                'otp_code' => '000000',
            ]);
            self::assertSame(401, $loginWithInvalidOtp['status']);
            self::assertSame('INVALID_2FA_CODE', $loginWithInvalidOtp['json']['code'] ?? null);

            $loginWithValidOtp = $this->requestJson($client, 'POST', '/api/v1/auth/login', [
                'email' => FixtureUsers::ADMIN_EMAIL,
                'password' => FixtureUsers::DEFAULT_PASSWORD,
                'otp_code' => $this->generateOtpCode($secret),
            ]);
            self::assertSame(200, $loginWithValidOtp['status']);
            self::assertSame('Bearer', $loginWithValidOtp['json']['token_type'] ?? null);
            $loginBearer = (string) ($loginWithValidOtp['json']['access_token'] ?? '');
            self::assertNotSame('', $loginBearer);
        } finally {
            if ($secret !== '' && $loginBearer !== '') {
                $disable = $this->requestJson($client, 'POST', '/api/v1/auth/2fa/disable', [
                    'otp_code' => $this->generateOtpCode($secret),
                ], [
                    'Authorization' => 'Bearer '.$loginBearer,
                ]);
                self::assertSame(200, $disable['status']);
                self::assertSame(false, $disable['json']['mfa_enabled'] ?? null);
            }
        }
    }

    private function createGuzzleClient(?string $remoteAddress = null, ?string $acceptLanguage = null): Client
    {
        self::ensureKernelShutdown();
        static::bootKernel();
        $resolvedRemoteAddress = $remoteAddress ?? sprintf('10.50.%d.%d', random_int(1, 200), random_int(1, 200));
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $this->ensureAuthClientTables($connection);
        $this->ensureUserAuthSessionTable($connection);
        $this->ensureUserTwoFactorStateTable($connection);

        /** @var HttpKernelInterface $httpKernel */
        $httpKernel = static::getContainer()->get(HttpKernelInterface::class);

        $handler = static function (RequestInterface $request) use ($httpKernel, $resolvedRemoteAddress, $acceptLanguage) {
            $uri = $request->getUri();
            $server = [
                'REQUEST_METHOD' => strtoupper($request->getMethod()),
                'REQUEST_URI' => $uri->getPath().($uri->getQuery() !== '' ? '?'.$uri->getQuery() : ''),
                'QUERY_STRING' => $uri->getQuery(),
                'SERVER_NAME' => $uri->getHost() !== '' ? $uri->getHost() : 'localhost',
                'SERVER_PORT' => $uri->getPort() ?? 80,
                'HTTPS' => 'off',
                'REMOTE_ADDR' => $resolvedRemoteAddress,
            ];

            if (is_string($acceptLanguage) && $acceptLanguage !== '') {
                $server['HTTP_ACCEPT_LANGUAGE'] = $acceptLanguage;
            }

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
        return $this->loginWithCredentialsAndGetBearerToken(
            $client,
            FixtureUsers::ADMIN_EMAIL,
            FixtureUsers::DEFAULT_PASSWORD
        );
    }

    private function loginWithCredentialsAndGetBearerToken(Client $client, string $email, string $password): string
    {
        $login = $this->requestJson($client, 'POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertSame(200, $login['status']);

        $token = (string) ($login['json']['access_token'] ?? '');
        self::assertNotSame('', $token);

        return $token;
    }

    private function generateOtpCode(string $secret): string
    {
        return TOTP::createFromSecret($secret)->now();
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
}
