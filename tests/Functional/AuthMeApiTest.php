<?php

namespace App\Tests\Functional;

use App\Tests\Support\ApiAuthClientTrait;
use App\Tests\Support\FixtureUsers;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AuthMeApiTest extends WebTestCase
{
    use RecreateDatabaseTrait;
    use ApiAuthClientTrait;

    public function testMeReturnsRicherCurrentUserShape(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $this->authenticateClient($client, FixtureUsers::ADMIN_EMAIL);
        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertIsString($payload['uuid'] ?? null);
        self::assertSame(FixtureUsers::ADMIN_EMAIL, $payload['email'] ?? null);
        self::assertArrayHasKey('display_name', $payload);
        self::assertSame(true, $payload['email_verified'] ?? null);
        self::assertSame(false, $payload['mfa_enabled'] ?? null);
        self::assertSame($payload['uuid'] ?? null, $payload['id'] ?? null);
    }

    public function testMeReflectsEnabledMfaState(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $this->authenticateClient($client, FixtureUsers::ADMIN_EMAIL);

        $client->request('POST', '/api/v1/auth/2fa/setup');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $setupPayload = json_decode((string) $client->getResponse()->getContent(), true);
        $secret = (string) ($setupPayload['secret'] ?? '');
        self::assertNotSame('', $secret);

        $client->jsonRequest('POST', '/api/v1/auth/2fa/enable', [
            'otp_code' => TOTP::create($secret)->now(),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(true, $payload['mfa_enabled'] ?? null);
    }
}
