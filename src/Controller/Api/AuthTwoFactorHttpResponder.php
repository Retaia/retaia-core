<?php

namespace App\Controller\Api;

use App\Application\Auth\TwoFactorDisableEndpointResult;
use App\Application\Auth\TwoFactorEnableEndpointResult;
use App\Application\Auth\TwoFactorRecoveryCodesEndpointResult;
use App\Application\Auth\TwoFactorSetupEndpointResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AuthTwoFactorHttpResponder
{
    public function __construct(
        private AuthApiErrorResponder $errors,
    ) {
    }

    public function setup(TwoFactorSetupEndpointResult $result): JsonResponse
    {
        if ($result->status() === TwoFactorSetupEndpointResult::STATUS_ALREADY_ENABLED) {
            return $this->errors->mfaAlreadyEnabled();
        }

        return new JsonResponse($result->setup(), Response::HTTP_OK);
    }

    public function enable(TwoFactorEnableEndpointResult $result): JsonResponse
    {
        return match ($result->status()) {
            TwoFactorEnableEndpointResult::STATUS_VALIDATION_FAILED => $this->errors->validationFailed('auth.error.otp_code_required'),
            TwoFactorEnableEndpointResult::STATUS_ALREADY_ENABLED => $this->errors->mfaAlreadyEnabled(),
            TwoFactorEnableEndpointResult::STATUS_SETUP_REQUIRED => $this->errors->validationFailed('auth.error.mfa_setup_required'),
            TwoFactorEnableEndpointResult::STATUS_INVALID_CODE => $this->errors->invalidTwoFactorCode(),
            default => new JsonResponse([
                'mfa_enabled' => true,
                'recovery_codes' => $result->recoveryCodes(),
            ], Response::HTTP_OK),
        };
    }

    public function disable(TwoFactorDisableEndpointResult $result): JsonResponse
    {
        return match ($result->status()) {
            TwoFactorDisableEndpointResult::STATUS_VALIDATION_FAILED => $this->errors->validationFailed('auth.error.otp_code_required'),
            TwoFactorDisableEndpointResult::STATUS_NOT_ENABLED => $this->errors->mfaNotEnabled(),
            TwoFactorDisableEndpointResult::STATUS_INVALID_CODE => $this->errors->invalidTwoFactorCode(),
            default => new JsonResponse(['mfa_enabled' => false], Response::HTTP_OK),
        };
    }

    public function regenerateRecoveryCodes(TwoFactorRecoveryCodesEndpointResult $result): JsonResponse
    {
        return match ($result->status()) {
            TwoFactorRecoveryCodesEndpointResult::STATUS_VALIDATION_FAILED => $this->errors->validationFailed('auth.error.otp_code_required'),
            TwoFactorRecoveryCodesEndpointResult::STATUS_NOT_ENABLED => $this->errors->mfaNotEnabled(),
            TwoFactorRecoveryCodesEndpointResult::STATUS_INVALID_CODE => $this->errors->invalidTwoFactorCode(),
            default => new JsonResponse(['recovery_codes' => $result->recoveryCodes()], Response::HTTP_OK),
        };
    }
}
