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
        self::assertStringContainsString('/swagger-ui/swagger-ui.css', $content);
        self::assertStringContainsString('/swagger-ui/swagger-ui-bundle.js', $content);
    }

    public function testOpenApiYamlIsAvailableForV1(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/openapi');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringContainsString('application/yaml', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString("openapi: 3.1.0\n", (string) $client->getResponse()->getContent());
        self::assertNotNull($client->getResponse()->headers->get('ETag'));
        self::assertNotNull($client->getResponse()->headers->get('Last-Modified'));

        $etag = (string) $client->getResponse()->headers->get('ETag');
        $client->request('GET', '/api/v1/openapi', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);
    }

    public function testMinorVersionWithoutDedicatedSpecReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1.1/docs');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('GET', '/api/v1.1/openapi');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUnknownOpenApiVersionReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v99/docs');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('GET', '/api/v99/openapi');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDefaultDocsRouteRedirectsToV1(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/docs');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertSame('/api/v1/docs', $client->getResponse()->headers->get('Location'));
    }
}
