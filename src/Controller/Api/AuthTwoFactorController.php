<?php

namespace App\Controller\Api;

use App\Application\Auth\AuthSelfServiceEndpointsHandler;
use App\Controller\RequestPayloadTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/auth')]
final class AuthTwoFactorController
{
    use RequestPayloadTrait;

    public function __construct(
        private AuthCurrentSessionResolver $currentSessionResolver,
        private AuthRateLimitGuard $rateLimitGuard,
        private AuthApiErrorResponder $errors,
        private AuthSelfServiceEndpointsHandler $authSelfServiceEndpointsHandler,
        private AuthTwoFactorHttpResponder $twoFactorResponder,
    ) {
    }

    #[Route('/2fa/setup', name: 'api_auth_2fa_setup', methods: ['POST'])]
    public function setup(Request $request): JsonResponse
    {
        $session = $this->currentSessionResolver->resolve($request);
        if (!is_array($session)) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }

        $throttled = $this->rateLimitGuard->consumeTwoFactorManage((string) $session['user_id'], 'setup', (string) ($request->getClientIp() ?? 'unknown'));
        if ($throttled instanceof JsonResponse) {
            return $throttled;
        }

        return $this->twoFactorResponder->setup($this->authSelfServiceEndpointsHandler->twoFactorSetup());
    }

    #[Route('/2fa/enable', name: 'api_auth_2fa_enable', methods: ['POST'])]
    public function enable(Request $request): JsonResponse
    {
        $session = $this->currentSessionResolver->resolve($request);
        if (!is_array($session)) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }

        $throttled = $this->rateLimitGuard->consumeTwoFactorManage((string) $session['user_id'], 'enable', (string) ($request->getClientIp() ?? 'unknown'));
        if ($throttled instanceof JsonResponse) {
            return $throttled;
        }

        return $this->twoFactorResponder->enable(
            $this->authSelfServiceEndpointsHandler->twoFactorEnable($this->payload($request))
        );
    }

    #[Route('/2fa/disable', name: 'api_auth_2fa_disable', methods: ['POST'])]
    public function disable(Request $request): JsonResponse
    {
        $session = $this->currentSessionResolver->resolve($request);
        if (!is_array($session)) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }

        $throttled = $this->rateLimitGuard->consumeTwoFactorManage((string) $session['user_id'], 'disable', (string) ($request->getClientIp() ?? 'unknown'));
        if ($throttled instanceof JsonResponse) {
            return $throttled;
        }

        return $this->twoFactorResponder->disable(
            $this->authSelfServiceEndpointsHandler->twoFactorDisable($this->payload($request))
        );
    }

    #[Route('/2fa/recovery-codes/regenerate', name: 'api_auth_2fa_recovery_codes_regenerate', methods: ['POST'])]
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $session = $this->currentSessionResolver->resolve($request);
        if (!is_array($session)) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }

        $throttled = $this->rateLimitGuard->consumeTwoFactorManage((string) $session['user_id'], 'recovery-regenerate', (string) ($request->getClientIp() ?? 'unknown'));
        if ($throttled instanceof JsonResponse) {
            return $throttled;
        }

        return $this->twoFactorResponder->regenerateRecoveryCodes(
            $this->authSelfServiceEndpointsHandler->regenerateTwoFactorRecoveryCodes($this->payload($request))
        );
    }
}
