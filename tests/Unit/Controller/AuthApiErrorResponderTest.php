<?php

namespace App\Tests\Unit\Controller;

use App\Tests\Support\TranslatorStubTrait;
use App\Controller\Api\AuthApiErrorResponder;
use PHPUnit\Framework\TestCase;

final class AuthApiErrorResponderTest extends TestCase
{
    use TranslatorStubTrait;

    public function testTranslatedIncludesDetails(): void
    {
        $responder = new AuthApiErrorResponder($this->translatorStub());
        $response = $responder->translated('VALIDATION_FAILED', 'auth.error.email_required', 422, ['field' => 'email']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'code' => 'VALIDATION_FAILED',
            'message' => 'auth.error.email_required',
            'details' => ['field' => 'email'],
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testTooManyAttemptsIncludesRetryInSeconds(): void
    {
        $responder = new AuthApiErrorResponder($this->translatorStub());
        $response = $responder->tooManyAttempts('auth.error.too_many_refresh_requests', 17);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame([
            'code' => 'TOO_MANY_ATTEMPTS',
            'message' => 'auth.error.too_many_refresh_requests',
            'retry_in_seconds' => 17,
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testSlowDownIncludesRetryInSeconds(): void
    {
        $responder = new AuthApiErrorResponder($this->translatorStub());
        $response = $responder->slowDown(9);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame([
            'code' => 'SLOW_DOWN',
            'message' => 'auth.error.slow_down',
            'retry_in_seconds' => 9,
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

}
