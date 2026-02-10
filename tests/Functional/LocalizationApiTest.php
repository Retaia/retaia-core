<?php

namespace App\Tests\Functional;

use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class LocalizationApiTest extends WebTestCase
{
    use RecreateDatabaseTrait;

    public function testApiUsesFrenchMessageWhenAcceptLanguageIsFrench(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->loginAdmin($client);

        $client->request('GET', '/api/v1/assets/00000000-0000-0000-0000-000000000000', server: [
            'HTTP_ACCEPT_LANGUAGE' => 'fr',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Asset introuvable', $payload['message'] ?? null);
    }

    public function testApiFallsBackToEnglishWhenLocaleIsUnsupported(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->loginAdmin($client);

        $client->request('GET', '/api/v1/assets/00000000-0000-0000-0000-000000000000', server: [
            'HTTP_ACCEPT_LANGUAGE' => 'de',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Asset not found', $payload['message'] ?? null);
    }

    private function loginAdmin($client): void
    {
        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }
}

