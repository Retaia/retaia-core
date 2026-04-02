<?php

namespace App\Tests\Unit\Controller;

use App\Controller\Api\ApiErrorResponseFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiErrorResponseFactoryTest extends TestCase
{
    public function testCreateBuildsStandardErrorEnvelope(): void
    {
        $response = ApiErrorResponseFactory::create('VALIDATION_FAILED', 'broken payload', Response::HTTP_BAD_REQUEST, [
            'field' => 'user_code',
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            json_encode([
                'code' => 'VALIDATION_FAILED',
                'message' => 'broken payload',
                'details' => ['field' => 'user_code'],
            ], JSON_THROW_ON_ERROR),
            (string) $response->getContent(),
        );
    }

    public function testCreateWithFieldsAppendsTopLevelFields(): void
    {
        $response = ApiErrorResponseFactory::createWithFields(
            'TOO_MANY_ATTEMPTS',
            'slow down',
            Response::HTTP_TOO_MANY_REQUESTS,
            ['retry_in_seconds' => 17]
        );

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            json_encode([
                'code' => 'TOO_MANY_ATTEMPTS',
                'message' => 'slow down',
                'retry_in_seconds' => 17,
            ], JSON_THROW_ON_ERROR),
            (string) $response->getContent(),
        );
    }
}
