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

    private AuthTwoFactorHttpResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responder = new AuthTwoFactorHttpResponder(new AuthApiErrorResponder($this->translatorStub()));
    }

    /**
     * @return array<mixed>
     */
    private function decodeJsonResponse(object $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testEnableReturnsRecoveryCodesOnSuccess(): void
    {
        $response = $this->responder->enable(new TwoFactorEnableEndpointResult(
            TwoFactorEnableEndpointResult::STATUS_ENABLED,
            ['one', 'two'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'mfa_enabled' => true,
            'recovery_codes' => ['one', 'two'],
        ], $this->decodeJsonResponse($response));
    }

    public function testEnableReturnsValidationFailedWhenOtpMissing(): void
    {
        $response = $this->responder->enable(new TwoFactorEnableEndpointResult(
            TwoFactorEnableEndpointResult::STATUS_VALIDATION_FAILED,
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'code' => 'VALIDATION_FAILED',
            'message' => 'auth.error.otp_code_required',
        ], $this->decodeJsonResponse($response));
    }

    public function testEnableReturnsInvalidCodeWhenOtpIsWrong(): void
    {
        $response = $this->responder->enable(new TwoFactorEnableEndpointResult(
            TwoFactorEnableEndpointResult::STATUS_INVALID_CODE,
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'code' => 'INVALID_2FA_CODE',
            'message' => 'auth.error.invalid_2fa_code',
        ], $this->decodeJsonResponse($response));
    }

    public function testEnableReturnsConflictWhenMfaIsAlreadyEnabled(): void
    {
        $response = $this->responder->enable(new TwoFactorEnableEndpointResult(
            TwoFactorEnableEndpointResult::STATUS_ALREADY_ENABLED,
        ));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame([
            'code' => 'MFA_ALREADY_ENABLED',
            'message' => 'auth.error.mfa_already_enabled',
        ], $this->decodeJsonResponse($response));
    }

    public function testEnableReturnsValidationFailedWhenSetupIsMissing(): void
    {
        $response = $this->responder->enable(new TwoFactorEnableEndpointResult(
            TwoFactorEnableEndpointResult::STATUS_SETUP_REQUIRED,
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'code' => 'VALIDATION_FAILED',
            'message' => 'auth.error.mfa_setup_required',
        ], $this->decodeJsonResponse($response));
    }

    public function testDisableReturnsDisabledPayloadOnSuccess(): void
    {
        $response = $this->responder->disable(new TwoFactorDisableEndpointResult(
            TwoFactorDisableEndpointResult::STATUS_DISABLED,
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'mfa_enabled' => false,
        ], $this->decodeJsonResponse($response));
    }

    public function testDisableReturnsValidationFailedWhenOtpMissing(): void
    {
        $response = $this->responder->disable(new TwoFactorDisableEndpointResult(
            TwoFactorDisableEndpointResult::STATUS_VALIDATION_FAILED,
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'code' => 'VALIDATION_FAILED',
            'message' => 'auth.error.otp_code_required',
        ], $this->decodeJsonResponse($response));
    }

    public function testDisableReturnsInvalidCodeWhenOtpIsWrong(): void
    {
        $response = $this->responder->disable(new TwoFactorDisableEndpointResult(
            TwoFactorDisableEndpointResult::STATUS_INVALID_CODE,
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'code' => 'INVALID_2FA_CODE',
            'message' => 'auth.error.invalid_2fa_code',
        ], $this->decodeJsonResponse($response));
    }

    public function testDisableReturnsConflictWhenMfaIsDisabled(): void
    {
        $response = $this->responder->disable(new TwoFactorDisableEndpointResult(
            TwoFactorDisableEndpointResult::STATUS_NOT_ENABLED,
        ));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame([
            'code' => 'MFA_NOT_ENABLED',
            'message' => 'auth.error.mfa_not_enabled',
        ], $this->decodeJsonResponse($response));
    }

    public function testRegenerateRecoveryCodesReturnsValidationFailedWhenOtpMissing(): void
    {
        $response = $this->responder->regenerateRecoveryCodes(new TwoFactorRecoveryCodesEndpointResult(
            TwoFactorRecoveryCodesEndpointResult::STATUS_VALIDATION_FAILED,
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'code' => 'VALIDATION_FAILED',
            'message' => 'auth.error.otp_code_required',
        ], $this->decodeJsonResponse($response));
    }

    public function testRegenerateRecoveryCodesReturnsInvalidCodeWhenOtpIsWrong(): void
    {
        $response = $this->responder->regenerateRecoveryCodes(new TwoFactorRecoveryCodesEndpointResult(
            TwoFactorRecoveryCodesEndpointResult::STATUS_INVALID_CODE,
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'code' => 'INVALID_2FA_CODE',
            'message' => 'auth.error.invalid_2fa_code',
        ], $this->decodeJsonResponse($response));
    }

    public function testRegenerateRecoveryCodesReturnsConflictWhenMfaIsDisabled(): void
    {
        $response = $this->responder->regenerateRecoveryCodes(new TwoFactorRecoveryCodesEndpointResult(
            TwoFactorRecoveryCodesEndpointResult::STATUS_NOT_ENABLED,
        ));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame([
            'code' => 'MFA_NOT_ENABLED',
            'message' => 'auth.error.mfa_not_enabled',
        ], $this->decodeJsonResponse($response));
    }

    public function testRegenerateRecoveryCodesReturnsRecoveryCodesOnSuccess(): void
    {
        $response = $this->responder->regenerateRecoveryCodes(new TwoFactorRecoveryCodesEndpointResult(
            TwoFactorRecoveryCodesEndpointResult::STATUS_REGENERATED,
            ['one', 'two'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'recovery_codes' => ['one', 'two'],
        ], $this->decodeJsonResponse($response));
    }
}
