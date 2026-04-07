<?php

namespace App\Tests\Unit\Controller;

use App\Tests\Support\TranslatorStubTrait;
use App\Application\Auth\TwoFactorDisableEndpointResult;
use App\Application\Auth\TwoFactorEnableEndpointResult;
use App\Application\Auth\TwoFactorRecoveryCodesEndpointResult;
use App\Controller\Api\AuthApiErrorResponder;
use App\Controller\Api\AuthTwoFactorHttpResponder;
use PHPUnit\Framework\TestCase;

final class AuthTwoFactorHttpResponderTest extends TestCase
{
    use TranslatorStubTrait;

    public function testEnableReturnsRecoveryCodesOnSuccess(): void
    {
        $responder = new AuthTwoFactorHttpResponder(new AuthApiErrorResponder($this->translatorStub()));
        $response = $responder->enable(new TwoFactorEnableEndpointResult(
            TwoFactorEnableEndpointResult::STATUS_ENABLED,
            ['one', 'two'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'mfa_enabled' => true,
            'recovery_codes' => ['one', 'two'],
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDisableReturnsDisabledPayloadOnSuccess(): void
    {
        $responder = new AuthTwoFactorHttpResponder(new AuthApiErrorResponder($this->translatorStub()));
        $response = $responder->disable(new TwoFactorDisableEndpointResult(
            TwoFactorDisableEndpointResult::STATUS_DISABLED,
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'mfa_enabled' => false,
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRegenerateRecoveryCodesReturnsValidationFailedWhenOtpMissing(): void
    {
        $responder = new AuthTwoFactorHttpResponder(new AuthApiErrorResponder($this->translatorStub()));
        $response = $responder->regenerateRecoveryCodes(new TwoFactorRecoveryCodesEndpointResult(
            TwoFactorRecoveryCodesEndpointResult::STATUS_VALIDATION_FAILED,
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'code' => 'VALIDATION_FAILED',
            'message' => 'auth.error.otp_code_required',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRegenerateRecoveryCodesReturnsInvalidCodeWhenOtpIsWrong(): void
    {
        $responder = new AuthTwoFactorHttpResponder(new AuthApiErrorResponder($this->translatorStub()));
        $response = $responder->regenerateRecoveryCodes(new TwoFactorRecoveryCodesEndpointResult(
            TwoFactorRecoveryCodesEndpointResult::STATUS_INVALID_CODE,
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'code' => 'INVALID_2FA_CODE',
            'message' => 'auth.error.invalid_2fa_code',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRegenerateRecoveryCodesReturnsConflictWhenMfaIsDisabled(): void
    {
        $responder = new AuthTwoFactorHttpResponder(new AuthApiErrorResponder($this->translatorStub()));
        $response = $responder->regenerateRecoveryCodes(new TwoFactorRecoveryCodesEndpointResult(
            TwoFactorRecoveryCodesEndpointResult::STATUS_NOT_ENABLED,
        ));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame([
            'code' => 'MFA_NOT_ENABLED',
            'message' => 'auth.error.mfa_not_enabled',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRegenerateRecoveryCodesReturnsRecoveryCodesOnSuccess(): void
    {
        $responder = new AuthTwoFactorHttpResponder(new AuthApiErrorResponder($this->translatorStub()));
        $response = $responder->regenerateRecoveryCodes(new TwoFactorRecoveryCodesEndpointResult(
            TwoFactorRecoveryCodesEndpointResult::STATUS_REGENERATED,
            ['one', 'two'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'recovery_codes' => ['one', 'two'],
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }
}
