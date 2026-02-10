<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class HealthApiTest extends WebTestCase
{
    public function testHealthEndpointReturnsOkStatus(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/health');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('ok', $payload['status'] ?? null);
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $client->getResponse()->headers->get('Permissions-Policy'));
    }
}
