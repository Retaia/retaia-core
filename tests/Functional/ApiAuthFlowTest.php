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
        $client = static::createClient();
        $client->disableReboot();

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
        $client = static::createClient();
        $client->disableReboot();

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
        $client = static::createClient();
        $client->disableReboot();

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
            'new_password' => 'new-password',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'new-password',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }
}
