<?php

namespace App\Tests\Unit\Controller;

use App\Application\Auth\RequestEmailVerificationEndpointResult;
use App\Application\Auth\RequestPasswordResetEndpointResult;
use App\Application\Auth\ResetPasswordEndpointResult;
use App\Controller\Api\AuthApiErrorResponder;
use App\Controller\Api\AuthRecoveryHttpResponder;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AuthRecoveryHttpResponderTest extends TestCase
{
    public function testResetUsesTranslatedFallbackViolation(): void
    {
        $responder = new AuthRecoveryHttpResponder(new AuthApiErrorResponder($this->translator()));
        $response = $responder->reset(new ResetPasswordEndpointResult(ResetPasswordEndpointResult::STATUS_VALIDATION_FAILED));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'code' => 'VALIDATION_FAILED',
            'message' => 'auth.error.token_new_password_required',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRequestResetAcceptedIncludesToken(): void
    {
        $responder = new AuthRecoveryHttpResponder(new AuthApiErrorResponder($this->translator()));
        $response = $responder->requestReset(new RequestPasswordResetEndpointResult(
            RequestPasswordResetEndpointResult::STATUS_ACCEPTED,
            'reset-token'
        ));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame([
            'accepted' => true,
            'reset_token' => 'reset-token',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRequestEmailVerificationAcceptedIncludesToken(): void
    {
        $responder = new AuthRecoveryHttpResponder(new AuthApiErrorResponder($this->translator()));
        $response = $responder->requestEmailVerification(new RequestEmailVerificationEndpointResult(
            RequestEmailVerificationEndpointResult::STATUS_ACCEPTED,
            'verify-token'
        ));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame([
            'accepted' => true,
            'verification_token' => 'verify-token',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
