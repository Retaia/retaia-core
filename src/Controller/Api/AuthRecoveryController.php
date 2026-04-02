<?php

namespace App\Controller\Api;

use App\Application\Auth\RequestEmailVerificationEndpointHandler;
use App\Application\Auth\RequestPasswordResetEndpointHandler;
use App\Application\Auth\ResetPasswordEndpointHandler;
use App\Application\Auth\VerifyEmailEndpointsHandler;
use App\Controller\RequestPayloadTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/auth')]
final class AuthRecoveryController
{
    use RequestPayloadTrait;

    public function __construct(
        private RequestPasswordResetEndpointHandler $requestPasswordResetEndpointHandler,
        private ResetPasswordEndpointHandler $resetPasswordEndpointHandler,
        private RequestEmailVerificationEndpointHandler $requestEmailVerificationEndpointHandler,
        private VerifyEmailEndpointsHandler $verifyEmailEndpointsHandler,
        private AuthRecoveryHttpResponder $recoveryResponder,
    ) {
    }

    #[Route('/lost-password/request', name: 'api_auth_lost_password_request', methods: ['POST'])]
    public function requestReset(Request $request): JsonResponse
    {
        $result = $this->requestPasswordResetEndpointHandler->handle(
            $this->payload($request),
            (string) ($request->getClientIp() ?? 'unknown')
        );

        return $this->recoveryResponder->requestReset($result);
    }

    #[Route('/lost-password/reset', name: 'api_auth_lost_password_reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        return $this->recoveryResponder->reset($this->resetPasswordEndpointHandler->handle($this->payload($request)));
    }

    #[Route('/verify-email/request', name: 'api_auth_verify_email_request', methods: ['POST'])]
    public function requestEmailVerification(Request $request): JsonResponse
    {
        $result = $this->requestEmailVerificationEndpointHandler->handle(
            $this->payload($request),
            (string) ($request->getClientIp() ?? 'unknown')
        );

        return $this->recoveryResponder->requestEmailVerification($result);
    }

    #[Route('/verify-email/confirm', name: 'api_auth_verify_email_confirm', methods: ['POST'])]
    public function confirmEmailVerification(Request $request): JsonResponse
    {
        return $this->recoveryResponder->confirmEmailVerification(
            $this->verifyEmailEndpointsHandler->confirm($this->payload($request))
        );
    }

    #[Route('/verify-email/admin-confirm', name: 'api_auth_verify_email_admin_confirm', methods: ['POST'])]
    public function adminConfirmEmailVerification(Request $request): JsonResponse
    {
        return $this->recoveryResponder->adminConfirmEmailVerification(
            $this->verifyEmailEndpointsHandler->adminConfirm($this->payload($request))
        );
    }
}
