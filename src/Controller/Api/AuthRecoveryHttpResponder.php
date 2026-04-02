<?php

namespace App\Controller\Api;

use App\Application\Auth\AdminConfirmEmailVerificationEndpointResult;
use App\Application\Auth\ConfirmEmailVerificationEndpointResult;
use App\Application\Auth\RequestEmailVerificationEndpointResult;
use App\Application\Auth\RequestPasswordResetEndpointResult;
use App\Application\Auth\ResetPasswordEndpointResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AuthRecoveryHttpResponder
{
    public function __construct(
        private AuthApiErrorResponder $errors,
    ) {
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
            ResetPasswordEndpointResult::STATUS_VALIDATION_FAILED => ApiErrorResponseFactory::create(
                'VALIDATION_FAILED',
                $result->violations()[0] ?? $this->errors->message('auth.error.token_new_password_required'),
                Response::HTTP_UNPROCESSABLE_ENTITY
            ),
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
