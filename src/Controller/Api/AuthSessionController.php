<?php

namespace App\Controller\Api;

use App\Application\Auth\AuthSelfServiceEndpointsHandler;
use App\Auth\UserAccessTokenService;
use App\Controller\RequestPayloadTrait;
use App\Domain\AuthClient\ClientKind;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/auth')]
final class AuthSessionController
{
    use RequestPayloadTrait;

    public function __construct(
        private AuthCurrentSessionResolver $currentSessionResolver,
        private AuthRateLimitGuard $rateLimitGuard,
        private AuthSessionHttpResponder $sessionResponder,
        private AuthApiErrorResponder $errors,
        private AuthSelfServiceEndpointsHandler $authSelfServiceEndpointsHandler,
        private UserAccessTokenService $userAccessTokenService,
    ) {
    }

    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException('This endpoint is handled by the security authenticator.');
    }

    #[Route('/refresh', name: 'api_auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $refreshToken = trim((string) ($payload['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            return $this->sessionResponder->refreshTokenRequired();
        }

        $clientId = trim((string) ($payload['client_id'] ?? ''));
        $clientId = $clientId !== '' ? $clientId : null;

        $clientKind = trim((string) ($payload['client_kind'] ?? ''));
        if ($clientKind !== '' && !ClientKind::isInteractive($clientKind)) {
            return $this->sessionResponder->refreshClientKindRequired();
        }

        $throttled = $this->rateLimitGuard->consumeRefresh(hash('sha256', $refreshToken.'|'.((string) ($request->getClientIp() ?? 'unknown'))));
        if ($throttled instanceof JsonResponse) {
            return $throttled;
        }

        $tokenPayload = $this->userAccessTokenService->refresh(
            $refreshToken,
            $clientId,
            $clientKind !== '' ? $clientKind : null
        );
        if (!is_array($tokenPayload)) {
            return $this->sessionResponder->refreshUnauthorized();
        }

        return new JsonResponse($tokenPayload, Response::HTTP_OK);
    }

    #[Route('/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($authorization, 'Bearer ')) {
            return $this->sessionResponder->logoutUnauthorized();
        }

        $accessToken = trim(substr($authorization, 7));
        if ($accessToken === '' || !$this->userAccessTokenService->revoke($accessToken)) {
            return $this->sessionResponder->logoutUnauthorized();
        }

        return $this->sessionResponder->logoutSuccess();
    }

    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        return $this->sessionResponder->me($this->authSelfServiceEndpointsHandler->me());
    }

    #[Route('/me/sessions', name: 'api_auth_me_sessions', methods: ['GET'])]
    public function meSessions(Request $request): JsonResponse
    {
        $session = $this->currentSessionResolver->resolve($request);
        if (!is_array($session)) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }

        return $this->sessionResponder->mySessions(
            $this->userAccessTokenService->sessionsForUser(
                (string) $session['user_id'],
                (string) $session['session_id']
            )
        );
    }

    #[Route('/me/sessions/{sessionId}/revoke', name: 'api_auth_me_sessions_revoke', methods: ['POST'])]
    public function revokeMySession(string $sessionId, Request $request): JsonResponse
    {
        $session = $this->currentSessionResolver->resolve($request);
        if (!is_array($session)) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }

        return $this->sessionResponder->revokeMySession($this->userAccessTokenService->revokeSession(
            (string) $session['user_id'],
            trim($sessionId),
            (string) $session['session_id']
        ));
    }

    #[Route('/me/sessions/revoke-others', name: 'api_auth_me_sessions_revoke_others', methods: ['POST'])]
    public function revokeOtherMySessions(Request $request): JsonResponse
    {
        $session = $this->currentSessionResolver->resolve($request);
        if (!is_array($session)) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }

        return $this->sessionResponder->revokeOtherMySessions($this->userAccessTokenService->revokeOtherSessions(
            (string) $session['user_id'],
            (string) $session['session_id']
        ));
    }
}
