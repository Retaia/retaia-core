<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiDocsControllerTest extends WebTestCase
{
    public function testDocsPageIsAvailableForV1(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/docs');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('SwaggerUIBundle', $content);
        self::assertStringContainsString('/api/v1/openapi', $content);
    }

    public function testOpenApiYamlIsAvailableForV1(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/openapi');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringContainsString('application/yaml', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString("openapi: 3.1.0\n", (string) $client->getResponse()->getContent());
    }

    public function testDocsAndOpenApiSupportMinorVersionFiles(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1.1/docs');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->request('GET', '/api/v1.1/openapi');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringContainsString("openapi: 3.1.0\n", (string) $client->getResponse()->getContent());
    }

    public function testUnknownOpenApiVersionReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v99/docs');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('GET', '/api/v99/openapi');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
