<?php

namespace App\Controller\Api;

use App\Application\Auth\AuthMeEndpointResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AuthSessionHttpResponder
{
    public function __construct(
        private AuthApiErrorResponder $errors,
    ) {
    }

    public function refreshTokenRequired(): JsonResponse
    {
        return $this->errors->validationFailed('auth.error.refresh_token_required');
    }

    public function refreshClientKindRequired(): JsonResponse
    {
        return $this->errors->validationFailed('auth.error.client_kind_required');
    }

    public function refreshTooManyAttempts(int $retryInSeconds): JsonResponse
    {
        return $this->errors->tooManyAttempts('auth.error.too_many_refresh_requests', $retryInSeconds);
    }

    public function refreshUnauthorized(): JsonResponse
    {
        return $this->errors->unauthorizedInvalidOrExpiredToken();
    }

    public function logoutUnauthorized(): JsonResponse
    {
        return $this->errors->unauthorizedAuthenticationRequired();
    }

    public function logoutSuccess(): JsonResponse
    {
        return new JsonResponse(['authenticated' => false], Response::HTTP_OK);
    }

    public function me(AuthMeEndpointResult $result): JsonResponse
    {
        if ($result->status() === AuthMeEndpointResult::STATUS_UNAUTHORIZED) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }

        return new JsonResponse([
            'id' => $result->id() ?? '',
            'uuid' => $result->id() ?? '',
            'email' => $result->email() ?? '',
            'display_name' => $result->displayName(),
            'email_verified' => $result->emailVerified(),
            'roles' => $result->roles(),
            'mfa_enabled' => $result->mfaEnabled(),
        ], Response::HTTP_OK);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function mySessions(array $items): JsonResponse
    {
        return new JsonResponse(['items' => $items], Response::HTTP_OK);
    }

    public function revokeMySession(string $result): JsonResponse
    {
        return match ($result) {
            'REVOKED' => new JsonResponse(null, Response::HTTP_OK),
            'CURRENT_SESSION' => $this->errors->stateConflict(),
            default => $this->errors->notFound('asset.error.not_found'),
        };
    }

    public function revokeOtherMySessions(int $revoked): JsonResponse
    {
        return new JsonResponse(['revoked' => $revoked], Response::HTTP_OK);
    }
}
