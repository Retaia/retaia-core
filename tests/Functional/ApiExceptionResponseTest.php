<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiExceptionResponseTest extends WebTestCase
{
    public function testUnknownApiRouteUsesStandardErrorEnvelope(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/does-not-exist', server: [
            'HTTP_X_CORRELATION_ID' => 'functional-exception-correlation',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertSame('functional-exception-correlation', $client->getResponse()->headers->get('X-Correlation-Id'));

        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('NOT_FOUND', $payload['code'] ?? null);
        self::assertSame('Not Found', $payload['message'] ?? null);
        self::assertFalse($payload['retryable'] ?? true);
        self::assertSame('functional-exception-correlation', $payload['correlation_id'] ?? null);
    }
}
