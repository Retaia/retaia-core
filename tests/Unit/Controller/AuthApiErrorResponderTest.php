<?php

namespace App\Tests\Unit\Controller;

use App\Controller\Api\AuthApiErrorResponder;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AuthApiErrorResponderTest extends TestCase
{
    public function testTranslatedIncludesDetails(): void
    {
        $responder = new AuthApiErrorResponder($this->translator());
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
        $responder = new AuthApiErrorResponder($this->translator());
        $response = $responder->tooManyAttempts('auth.error.too_many_refresh_requests', 17);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame([
            'code' => 'TOO_MANY_ATTEMPTS',
            'message' => 'auth.error.too_many_refresh_requests',
            'retry_in_seconds' => 17,
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
