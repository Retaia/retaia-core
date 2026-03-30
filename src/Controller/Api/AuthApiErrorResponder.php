<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AuthApiErrorResponder
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function translated(string $code, string $messageKey, int $status, array $details = []): JsonResponse
    {
        $payload = [
            'code' => $code,
            'message' => $this->translator->trans($messageKey),
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return new JsonResponse($payload, $status);
    }

    public function unauthorizedAuthenticationRequired(): JsonResponse
    {
        return $this->translated('UNAUTHORIZED', 'auth.error.authentication_required', Response::HTTP_UNAUTHORIZED);
    }

    public function message(string $messageKey): string
    {
        return $this->translator->trans($messageKey);
    }

    public function invalidOrExpiredToken(): JsonResponse
    {
        return $this->translated('INVALID_TOKEN', 'auth.error.invalid_or_expired_token', Response::HTTP_BAD_REQUEST);
    }

    public function unauthorizedInvalidOrExpiredToken(): JsonResponse
    {
        return $this->translated('UNAUTHORIZED', 'auth.error.invalid_or_expired_token', Response::HTTP_UNAUTHORIZED);
    }

    public function validationFailed(string $messageKey): JsonResponse
    {
        return $this->translated('VALIDATION_FAILED', $messageKey, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, mixed> $details
     */
    public function validationFailedWithDetails(string $messageKey, array $details): JsonResponse
    {
        return $this->translated('VALIDATION_FAILED', $messageKey, Response::HTTP_UNPROCESSABLE_ENTITY, $details);
    }

    public function forbiddenActor(): JsonResponse
    {
        return $this->translated('FORBIDDEN_ACTOR', 'auth.error.forbidden_actor', Response::HTTP_FORBIDDEN);
    }

    public function forbiddenScope(): JsonResponse
    {
        return $this->translated('FORBIDDEN_SCOPE', 'auth.error.forbidden_scope', Response::HTTP_FORBIDDEN);
    }

    public function invalidTwoFactorCode(): JsonResponse
    {
        return $this->translated('INVALID_2FA_CODE', 'auth.error.invalid_2fa_code', Response::HTTP_BAD_REQUEST);
    }

    public function mfaAlreadyEnabled(): JsonResponse
    {
        return $this->translated('MFA_ALREADY_ENABLED', 'auth.error.mfa_already_enabled', Response::HTTP_CONFLICT);
    }

    public function mfaNotEnabled(): JsonResponse
    {
        return $this->translated('MFA_NOT_ENABLED', 'auth.error.mfa_not_enabled', Response::HTTP_CONFLICT);
    }

    public function stateConflict(): JsonResponse
    {
        return $this->translated('STATE_CONFLICT', 'auth.error.state_conflict', Response::HTTP_CONFLICT);
    }

    public function notFound(string $messageKey): JsonResponse
    {
        return $this->translated('NOT_FOUND', $messageKey, Response::HTTP_NOT_FOUND);
    }

    public function userNotFound(): JsonResponse
    {
        return $this->translated('USER_NOT_FOUND', 'auth.error.unknown_user', Response::HTTP_NOT_FOUND);
    }

    public function invalidDeviceCode(): JsonResponse
    {
        return $this->translated('INVALID_DEVICE_CODE', 'auth.error.invalid_device_code', Response::HTTP_BAD_REQUEST);
    }

    public function expiredDeviceCode(): JsonResponse
    {
        return $this->translated('EXPIRED_DEVICE_CODE', 'auth.error.expired_device_code', Response::HTTP_BAD_REQUEST);
    }

    public function tooManyAttempts(string $messageKey, int $retryInSeconds): JsonResponse
    {
        return new JsonResponse([
            'code' => 'TOO_MANY_ATTEMPTS',
            'message' => $this->translator->trans($messageKey),
            'retry_in_seconds' => $retryInSeconds,
        ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function slowDown(int $retryInSeconds): JsonResponse
    {
        return new JsonResponse([
            'code' => 'SLOW_DOWN',
            'message' => $this->translator->trans('auth.error.slow_down'),
            'retry_in_seconds' => $retryInSeconds,
        ], Response::HTTP_TOO_MANY_REQUESTS);
    }
}
