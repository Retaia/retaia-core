<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FixtureUsers;
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

        $token = (string) $login['json']['access_token'];

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
