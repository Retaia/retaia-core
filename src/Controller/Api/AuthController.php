<?php

namespace App\Controller\Api;

use App\Application\Auth\RequestPasswordResetEndpointHandler;
use App\Application\Auth\RequestEmailVerificationEndpointHandler;
use App\Application\Auth\ResetPasswordEndpointHandler;
use App\Application\Auth\AuthSelfServiceEndpointsHandler;
use App\Application\Auth\VerifyEmailEndpointsHandler;
use App\Application\AuthClient\AuthClientAdminEndpointsHandler;
use App\Application\AuthClient\AuthClientDeviceFlowEndpointsHandler;
use App\Application\AuthClient\MintClientTokenEndpointHandler;
use App\Auth\UserAccessTokenService;
use App\Controller\RequestPayloadTrait;
use App\Domain\AuthClient\ClientKind;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/auth')]
final class AuthController
{
    use RequestPayloadTrait;

    public function __construct(
        private RequestPasswordResetEndpointHandler $requestPasswordResetEndpointHandler,
        private ResetPasswordEndpointHandler $resetPasswordEndpointHandler,
        private RequestEmailVerificationEndpointHandler $requestEmailVerificationEndpointHandler,
        private VerifyEmailEndpointsHandler $verifyEmailEndpointsHandler,
        private AuthSelfServiceEndpointsHandler $authSelfServiceEndpointsHandler,
        private AuthApiErrorResponder $errors,
        private AuthSessionHttpResponder $sessionResponder,
        private AuthSelfServiceHttpResponder $selfServiceResponder,
        private AuthClientHttpResponder $clientResponder,
        private MintClientTokenEndpointHandler $mintClientTokenEndpointHandler,
        private AuthClientAdminEndpointsHandler $authClientAdminEndpointsHandler,
        private AuthClientDeviceFlowEndpointsHandler $authClientDeviceFlowEndpointsHandler,
        private UserAccessTokenService $userAccessTokenService,
        #[Autowire(service: 'limiter.auth_refresh')]
        private RateLimiterFactory $refreshRateLimiter,
        #[Autowire(service: 'limiter.auth_2fa_manage')]
        private RateLimiterFactory $twoFactorManageRateLimiter,
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

        $remoteAddress = (string) ($request->getClientIp() ?? 'unknown');
        $throttled = $this->consumeRefreshRateLimit(hash('sha256', $refreshToken.'|'.$remoteAddress));
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
        $session = $this->requireCurrentSession($request);
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
        $session = $this->requireCurrentSession($request);
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
        $session = $this->requireCurrentSession($request);
        if (!is_array($session)) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }

        return $this->sessionResponder->revokeOtherMySessions($this->userAccessTokenService->revokeOtherSessions(
                (string) $session['user_id'],
                (string) $session['session_id']
            ));
    }

    #[Route('/2fa/setup', name: 'api_auth_2fa_setup', methods: ['POST'])]
    public function twoFactorSetup(Request $request): JsonResponse
    {
        $session = $this->requireCurrentSession($request);
        if (!is_array($session)) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }
        $throttled = $this->consumeTwoFactorManageRateLimit((string) $session['user_id'], 'setup', (string) ($request->getClientIp() ?? 'unknown'));
        if ($throttled instanceof JsonResponse) {
            return $throttled;
        }

        return $this->selfServiceResponder->twoFactorSetup($this->authSelfServiceEndpointsHandler->twoFactorSetup());
    }

    #[Route('/2fa/enable', name: 'api_auth_2fa_enable', methods: ['POST'])]
    public function twoFactorEnable(Request $request): JsonResponse
    {
        $session = $this->requireCurrentSession($request);
        if (!is_array($session)) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }
        $throttled = $this->consumeTwoFactorManageRateLimit((string) $session['user_id'], 'enable', (string) ($request->getClientIp() ?? 'unknown'));
        if ($throttled instanceof JsonResponse) {
            return $throttled;
        }

        return $this->selfServiceResponder->twoFactorEnable(
            $this->authSelfServiceEndpointsHandler->twoFactorEnable($this->payload($request))
        );
    }

    #[Route('/2fa/disable', name: 'api_auth_2fa_disable', methods: ['POST'])]
    public function twoFactorDisable(Request $request): JsonResponse
    {
        $session = $this->requireCurrentSession($request);
        if (!is_array($session)) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }
        $throttled = $this->consumeTwoFactorManageRateLimit((string) $session['user_id'], 'disable', (string) ($request->getClientIp() ?? 'unknown'));
        if ($throttled instanceof JsonResponse) {
            return $throttled;
        }

        return $this->selfServiceResponder->twoFactorDisable(
            $this->authSelfServiceEndpointsHandler->twoFactorDisable($this->payload($request))
        );
    }

    #[Route('/2fa/recovery-codes/regenerate', name: 'api_auth_2fa_recovery_codes_regenerate', methods: ['POST'])]
    public function regenerateTwoFactorRecoveryCodes(Request $request): JsonResponse
    {
        $session = $this->requireCurrentSession($request);
        if (!is_array($session)) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }
        $throttled = $this->consumeTwoFactorManageRateLimit((string) $session['user_id'], 'recovery-regenerate', (string) ($request->getClientIp() ?? 'unknown'));
        if ($throttled instanceof JsonResponse) {
            return $throttled;
        }

        return $this->selfServiceResponder->regenerateTwoFactorRecoveryCodes(
            $this->authSelfServiceEndpointsHandler->regenerateTwoFactorRecoveryCodes($this->payload($request))
        );
    }

    #[Route('/me/features', name: 'api_auth_me_features_get', methods: ['GET'])]
    public function meFeatures(): JsonResponse
    {
        return $this->selfServiceResponder->meFeatures($this->authSelfServiceEndpointsHandler->getMyFeatures());
    }

    #[Route('/me/features', name: 'api_auth_me_features_patch', methods: ['PATCH'])]
    public function patchMeFeatures(Request $request): JsonResponse
    {
        return $this->selfServiceResponder->meFeatures(
            $this->authSelfServiceEndpointsHandler->patchMyFeatures($this->payload($request))
        );
    }

    #[Route('/lost-password/request', name: 'api_auth_lost_password_request', methods: ['POST'])]
    public function requestReset(Request $request): JsonResponse
    {
        $result = $this->requestPasswordResetEndpointHandler->handle(
            $this->payload($request),
            (string) ($request->getClientIp() ?? 'unknown')
        );
        return $this->selfServiceResponder->requestReset($result);
    }

    #[Route('/lost-password/reset', name: 'api_auth_lost_password_reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        return $this->selfServiceResponder->reset($this->resetPasswordEndpointHandler->handle($this->payload($request)));
    }

    #[Route('/verify-email/request', name: 'api_auth_verify_email_request', methods: ['POST'])]
    public function requestEmailVerification(Request $request): JsonResponse
    {
        $result = $this->requestEmailVerificationEndpointHandler->handle(
            $this->payload($request),
            (string) ($request->getClientIp() ?? 'unknown')
        );
        return $this->selfServiceResponder->requestEmailVerification($result);
    }

    #[Route('/verify-email/confirm', name: 'api_auth_verify_email_confirm', methods: ['POST'])]
    public function confirmEmailVerification(Request $request): JsonResponse
    {
        return $this->selfServiceResponder->confirmEmailVerification(
            $this->verifyEmailEndpointsHandler->confirm($this->payload($request))
        );
    }

    #[Route('/verify-email/admin-confirm', name: 'api_auth_verify_email_admin_confirm', methods: ['POST'])]
    public function adminConfirmEmailVerification(Request $request): JsonResponse
    {
        return $this->selfServiceResponder->adminConfirmEmailVerification(
            $this->verifyEmailEndpointsHandler->adminConfirm($this->payload($request))
        );
    }

    #[Route('/clients/token', name: 'api_auth_clients_token', methods: ['POST'])]
    public function clientToken(Request $request): JsonResponse
    {
        return $this->clientResponder->clientToken($this->mintClientTokenEndpointHandler->handle(
            $this->payload($request),
            (string) ($request->getClientIp() ?? 'unknown')
        ));
    }

    #[Route('/clients/{clientId}/revoke-token', name: 'api_auth_clients_revoke_token', methods: ['POST'])]
    public function revokeClientToken(string $clientId): JsonResponse
    {
        return $this->clientResponder->revokeClientToken($clientId, $this->authClientAdminEndpointsHandler->revoke($clientId));
    }

    #[Route('/clients/{clientId}/rotate-secret', name: 'api_auth_clients_rotate_secret', methods: ['POST'])]
    public function rotateClientSecret(string $clientId): JsonResponse
    {
        return $this->clientResponder->rotateClientSecret($clientId, $this->authClientAdminEndpointsHandler->rotate($clientId));
    }

    #[Route('/clients/device/start', name: 'api_auth_clients_device_start', methods: ['POST'])]
    public function startDeviceFlow(Request $request): JsonResponse
    {
        return $this->clientResponder->startDeviceFlow($this->authClientDeviceFlowEndpointsHandler->start(
            $this->payload($request),
            (string) ($request->getClientIp() ?? 'unknown')
        ));
    }

    #[Route('/clients/device/poll', name: 'api_auth_clients_device_poll', methods: ['POST'])]
    public function pollDeviceFlow(Request $request): JsonResponse
    {
        return $this->clientResponder->pollDeviceFlow(
            $this->authClientDeviceFlowEndpointsHandler->poll($this->payload($request))
        );
    }

    #[Route('/clients/device/cancel', name: 'api_auth_clients_device_cancel', methods: ['POST'])]
    public function cancelDeviceFlow(Request $request): JsonResponse
    {
        return $this->clientResponder->cancelDeviceFlow(
            $this->authClientDeviceFlowEndpointsHandler->cancel($this->payload($request))
        );
    }

    /**
     * @return array{user_id: string, email: string, client_id: string, client_kind: string, session_id: string}|null
     */
    private function requireCurrentSession(Request $request): ?array
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        return $this->userAccessTokenService->validate(trim(substr($authorization, 7)));
    }

    private function consumeRefreshRateLimit(string $key): ?JsonResponse
    {
        $limit = $this->refreshRateLimiter->create($key)->consume(1);
        if ($limit->isAccepted()) {
            return null;
        }

        $retryAfter = $limit->getRetryAfter();
        $retryInSeconds = max(
            1,
            $retryAfter instanceof \DateTimeImmutable ? $retryAfter->getTimestamp() - time() : 1
        );

        return $this->sessionResponder->refreshTooManyAttempts($retryInSeconds);
    }

    private function consumeTwoFactorManageRateLimit(string $userId, string $action, string $remoteAddress): ?JsonResponse
    {
        $limit = $this->twoFactorManageRateLimiter
            ->create(hash('sha256', $userId.'|'.$action.'|'.$remoteAddress))
            ->consume(1);
        if ($limit->isAccepted()) {
            return null;
        }

        $retryAfter = $limit->getRetryAfter();
        $retryInSeconds = max(
            1,
            $retryAfter instanceof \DateTimeImmutable ? $retryAfter->getTimestamp() - time() : 1
        );

        return $this->errors->tooManyAttempts('auth.error.too_many_2fa_attempts', $retryInSeconds);
    }

}
