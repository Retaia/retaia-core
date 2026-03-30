<?php

namespace App\Controller\Api;

use App\Application\Auth\AdminConfirmEmailVerificationEndpointResult;
use App\Application\Auth\ConfirmEmailVerificationEndpointResult;
use App\Application\Auth\GetMyFeaturesEndpointResult;
use App\Application\Auth\MyFeaturesResult;
use App\Application\Auth\PatchMyFeaturesEndpointResult;
use App\Application\Auth\RequestEmailVerificationEndpointResult;
use App\Application\Auth\RequestPasswordResetEndpointResult;
use App\Application\Auth\ResetPasswordEndpointResult;
use App\Application\Auth\TwoFactorDisableEndpointResult;
use App\Application\Auth\TwoFactorEnableEndpointResult;
use App\Application\Auth\TwoFactorRecoveryCodesEndpointResult;
use App\Application\Auth\TwoFactorSetupEndpointResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AuthSelfServiceHttpResponder
{
    public function __construct(
        private AuthApiErrorResponder $errors,
    ) {
    }

    public function twoFactorSetup(TwoFactorSetupEndpointResult $result): JsonResponse
    {
        if ($result->status() === TwoFactorSetupEndpointResult::STATUS_ALREADY_ENABLED) {
            return $this->errors->mfaAlreadyEnabled();
        }

        return new JsonResponse($result->setup(), Response::HTTP_OK);
    }

    public function twoFactorEnable(TwoFactorEnableEndpointResult $result): JsonResponse
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

    public function twoFactorDisable(TwoFactorDisableEndpointResult $result): JsonResponse
    {
        return match ($result->status()) {
            TwoFactorDisableEndpointResult::STATUS_VALIDATION_FAILED => $this->errors->validationFailed('auth.error.otp_code_required'),
            TwoFactorDisableEndpointResult::STATUS_NOT_ENABLED => $this->errors->mfaNotEnabled(),
            TwoFactorDisableEndpointResult::STATUS_INVALID_CODE => $this->errors->invalidTwoFactorCode(),
            default => new JsonResponse(['mfa_enabled' => false], Response::HTTP_OK),
        };
    }

    public function regenerateTwoFactorRecoveryCodes(TwoFactorRecoveryCodesEndpointResult $result): JsonResponse
    {
        return match ($result->status()) {
            TwoFactorRecoveryCodesEndpointResult::STATUS_VALIDATION_FAILED => $this->errors->validationFailed('auth.error.otp_code_required'),
            TwoFactorRecoveryCodesEndpointResult::STATUS_NOT_ENABLED => $this->errors->mfaNotEnabled(),
            TwoFactorRecoveryCodesEndpointResult::STATUS_INVALID_CODE => $this->errors->invalidTwoFactorCode(),
            default => new JsonResponse(['recovery_codes' => $result->recoveryCodes()], Response::HTTP_OK),
        };
    }

    public function meFeatures(GetMyFeaturesEndpointResult|PatchMyFeaturesEndpointResult $result): JsonResponse
    {
        if ($result->status() === GetMyFeaturesEndpointResult::STATUS_UNAUTHORIZED || $result->status() === PatchMyFeaturesEndpointResult::STATUS_UNAUTHORIZED) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }
        if ($result instanceof PatchMyFeaturesEndpointResult) {
            if ($result->status() === PatchMyFeaturesEndpointResult::STATUS_VALIDATION_FAILED_PAYLOAD) {
                return $this->errors->validationFailed('auth.error.invalid_user_feature_payload');
            }
            if ($result->status() === PatchMyFeaturesEndpointResult::STATUS_FORBIDDEN_SCOPE) {
                return $this->errors->forbiddenScope();
            }
            if ($result->status() === PatchMyFeaturesEndpointResult::STATUS_VALIDATION_FAILED) {
                return $this->errors->validationFailedWithDetails('auth.error.invalid_user_feature_payload', $result->validationDetails() ?? []);
            }
        }

        return new JsonResponse($this->featuresPayload($result->features()), Response::HTTP_OK);
    }

    public function requestReset(RequestPasswordResetEndpointResult $result): JsonResponse
    {
        return $this->acceptedRequestWithToken(
            $result->status(),
            RequestPasswordResetEndpointResult::STATUS_VALIDATION_FAILED,
            RequestPasswordResetEndpointResult::STATUS_TOO_MANY_ATTEMPTS,
            $result->retryInSeconds(),
            $result->token(),
            'auth.error.email_required',
            'auth.error.too_many_password_reset_requests',
            'reset_token'
        );
    }

    public function reset(ResetPasswordEndpointResult $result): JsonResponse
    {
        return match ($result->status()) {
            ResetPasswordEndpointResult::STATUS_VALIDATION_FAILED => new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => $result->violations()[0] ?? $this->errors->message('auth.error.token_new_password_required'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY),
            ResetPasswordEndpointResult::STATUS_INVALID_TOKEN => $this->errors->invalidOrExpiredToken(),
            default => new JsonResponse(['password_reset' => true], Response::HTTP_OK),
        };
    }

    public function requestEmailVerification(RequestEmailVerificationEndpointResult $result): JsonResponse
    {
        return $this->acceptedRequestWithToken(
            $result->status(),
            RequestEmailVerificationEndpointResult::STATUS_VALIDATION_FAILED,
            RequestEmailVerificationEndpointResult::STATUS_TOO_MANY_ATTEMPTS,
            $result->retryInSeconds(),
            $result->token(),
            'auth.error.email_required',
            'auth.error.too_many_verification_requests',
            'verification_token'
        );
    }

    public function confirmEmailVerification(ConfirmEmailVerificationEndpointResult $result): JsonResponse
    {
        return match ($result->status()) {
            ConfirmEmailVerificationEndpointResult::STATUS_VALIDATION_FAILED => $this->errors->validationFailed('auth.error.token_required'),
            ConfirmEmailVerificationEndpointResult::STATUS_INVALID_TOKEN => $this->errors->invalidOrExpiredToken(),
            default => new JsonResponse(['email_verified' => true], Response::HTTP_OK),
        };
    }

    public function adminConfirmEmailVerification(AdminConfirmEmailVerificationEndpointResult $result): JsonResponse
    {
        return match ($result->status()) {
            AdminConfirmEmailVerificationEndpointResult::STATUS_FORBIDDEN_ACTOR => $this->errors->forbiddenActor(),
            AdminConfirmEmailVerificationEndpointResult::STATUS_VALIDATION_FAILED => $this->errors->validationFailed('auth.error.email_required'),
            AdminConfirmEmailVerificationEndpointResult::STATUS_USER_NOT_FOUND => $this->errors->userNotFound(),
            default => new JsonResponse(['email_verified' => true], Response::HTTP_OK),
        };
    }

    private function featuresPayload(?MyFeaturesResult $features): array
    {
        return [
            'user_feature_enabled' => $features?->userFeatureEnabled() ?? [],
            'effective_feature_enabled' => $features?->effectiveFeatureEnabled() ?? [],
            'effective_feature_explanations' => $features?->effectiveFeatureExplanations() ?? [],
            'feature_governance' => $features?->featureGovernance() ?? [],
            'core_v1_global_features' => $features?->coreV1GlobalFeatures() ?? [],
        ];
    }

    private function acceptedRequestWithToken(
        string $status,
        string $validationFailedStatus,
        string $tooManyAttemptsStatus,
        ?int $retryInSeconds,
        ?string $token,
        string $validationMessageKey,
        string $tooManyAttemptsMessageKey,
        string $tokenKey,
    ): JsonResponse {
        if ($status === $validationFailedStatus) {
            return $this->errors->validationFailed($validationMessageKey);
        }
        if ($status === $tooManyAttemptsStatus) {
            return $this->errors->tooManyAttempts($tooManyAttemptsMessageKey, $retryInSeconds ?? 60);
        }

        $response = ['accepted' => true];
        if ($token !== null) {
            $response[$tokenKey] = $token;
        }

        return new JsonResponse($response, Response::HTTP_ACCEPTED);
    }
}
