<?php

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
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
        self::assertGreaterThanOrEqual(1, (int) ($payload['retry_in_minutes'] ?? 0));
    }

    public function testLostPasswordResetFailsWhenTokenExpired(): void
    {
        $client = $this->createIsolatedClient('10.0.0.16');

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', [
            'email' => 'admin@retaia.local',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $requestPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($requestPayload);
        $token = $requestPayload['reset_token'] ?? null;
        self::assertIsString($token);

        $this->forceTokenExpired($token);

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/reset', [
            'token' => $token,
            'new_password' => 'New-password1!',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('INVALID_TOKEN', $payload['code'] ?? null);
    }

    public function testLogoutWithoutAuthenticationReturns401(): void
    {
        $client = $this->createIsolatedClient('10.0.0.17');

        $client->jsonRequest('POST', '/api/v1/auth/logout');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('UNAUTHORIZED', $payload['code'] ?? null);
    }

    private function forceTokenExpired(string $token): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE password_reset_token SET expires_at = :expiresAt WHERE token_hash = :tokenHash',
            [
                'expiresAt' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
                'tokenHash' => hash('sha256', $token),
            ],
        );
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

    public function testEmailVerificationFlowEnablesLogin(): void
    {
        $client = $this->createIsolatedClient('10.0.0.18');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'pending@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->jsonRequest('POST', '/api/v1/auth/verify-email/request', [
            'email' => 'pending@retaia.local',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $requestPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($requestPayload);
        $token = $requestPayload['verification_token'] ?? null;
        self::assertIsString($token);

        $client->jsonRequest('POST', '/api/v1/auth/verify-email/confirm', [
            'token' => $token,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $confirmPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(true, $confirmPayload['email_verified'] ?? null);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'pending@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testEmailVerificationConfirmRejectsInvalidToken(): void
    {
        $client = $this->createIsolatedClient('10.0.0.19');

        $client->jsonRequest('POST', '/api/v1/auth/verify-email/confirm', [
            'token' => 'invalid-token',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('INVALID_TOKEN', $payload['code'] ?? null);
    }

    public function testVerifyEmailRequestIsRateLimited(): void
    {
        $client = $this->createIsolatedClient('10.0.0.21');

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $client->jsonRequest('POST', '/api/v1/auth/verify-email/request', [
                'email' => 'pending@retaia.local',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        }

        $client->jsonRequest('POST', '/api/v1/auth/verify-email/request', [
            'email' => 'pending@retaia.local',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('TOO_MANY_ATTEMPTS', $payload['code'] ?? null);
        self::assertGreaterThanOrEqual(1, (int) ($payload['retry_in_seconds'] ?? 0));
    }

    private function createIsolatedClient(string $ipAddress): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient([], ['REMOTE_ADDR' => $ipAddress]);
        $client->disableReboot();

        return $client;
    }
}
