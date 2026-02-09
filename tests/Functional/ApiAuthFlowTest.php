<?php

namespace App\Tests\Functional;

use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiAuthFlowTest extends WebTestCase
{
    use RecreateDatabaseTrait;

    public function testLoginAndMeFlowWithSession(): void
    {
        $client = $this->createIsolatedClient('10.0.0.11');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertResponseHeaderSame('content-type', 'application/json');
        $loginPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($loginPayload);
        self::assertTrue((bool) ($loginPayload['authenticated'] ?? false));
        self::assertSame('admin@retaia.local', $loginPayload['user']['email'] ?? null);

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $mePayload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('admin@retaia.local', $mePayload['email'] ?? null);
    }

    public function testLogoutInvalidatesSession(): void
    {
        $client = $this->createIsolatedClient('10.0.0.12');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/logout');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $logoutPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($logoutPayload);
        self::assertFalse((bool) ($logoutPayload['authenticated'] ?? true));

        $client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLostPasswordResetFlow(): void
    {
        $client = $this->createIsolatedClient('10.0.0.13');

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', [
            'email' => 'admin@retaia.local',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $requestPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($requestPayload);
        self::assertSame(true, $requestPayload['accepted'] ?? null);

        $token = $requestPayload['reset_token'] ?? null;
        self::assertIsString($token);

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/reset', [
            'token' => $token,
            'new_password' => 'New-password1!',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'New-password1!',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testLostPasswordRejectsWeakPassword(): void
    {
        $client = $this->createIsolatedClient('10.0.0.14');

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', [
            'email' => 'admin@retaia.local',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $requestPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($requestPayload);
        $token = $requestPayload['reset_token'] ?? null;
        self::assertIsString($token);

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/reset', [
            'token' => $token,
            'new_password' => 'short',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
    }

    public function testLoginThrottlingReturns429AfterTooManyFailures(): void
    {
        $client = $this->createIsolatedClient(sprintf('10.0.%d.%d', random_int(1, 200), random_int(1, 200)));

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $client->jsonRequest('POST', '/api/v1/auth/login', [
                'email' => 'admin@retaia.local',
                'password' => 'invalid-password',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'invalid-password',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('TOO_MANY_ATTEMPTS', $payload['code'] ?? null);
    }

    public function testLoginFailsWhenEmailIsNotVerified(): void
    {
        $client = $this->createIsolatedClient('10.0.0.16');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'pending@retaia.local',
            'password' => 'change-me',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('EMAIL_NOT_VERIFIED', $payload['code'] ?? null);
    }

    public function testLostPasswordRejectsPasswordWithoutSpecialCharacter(): void
    {
        $client = $this->createIsolatedClient('10.0.0.17');

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', [
            'email' => 'admin@retaia.local',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $requestPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($requestPayload);
        $token = $requestPayload['reset_token'] ?? null;
        self::assertIsString($token);

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/reset', [
            'token' => $token,
            'new_password' => 'NoSpecial1234',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
        self::assertSame('new_password must include at least one special character', $payload['message'] ?? null);
    }

    private function createIsolatedClient(string $ipAddress): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient([], ['REMOTE_ADDR' => $ipAddress]);
        $client->disableReboot();

        return $client;
    }
}
