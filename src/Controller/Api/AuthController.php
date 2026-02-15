<?php

namespace App\Controller\Api;

use App\Application\Auth\RequestPasswordResetEndpointHandler;
use App\Application\Auth\RequestPasswordResetEndpointResult;
use App\Application\Auth\ResetPasswordEndpointHandler;
use App\Application\Auth\ResetPasswordEndpointResult;
use App\Application\Auth\AuthSelfServiceEndpointsHandler;
use App\Application\Auth\AuthMeEndpointResult;
use App\Application\Auth\TwoFactorSetupEndpointResult;
use App\Application\Auth\TwoFactorEnableEndpointResult;
use App\Application\Auth\TwoFactorDisableEndpointResult;
use App\Application\Auth\TwoFactorRecoveryCodesEndpointResult;
use App\Application\Auth\GetMyFeaturesEndpointResult;
use App\Application\Auth\PatchMyFeaturesEndpointResult;
use App\Application\Auth\AdminConfirmEmailVerificationEndpointResult;
use App\Application\Auth\ConfirmEmailVerificationEndpointResult;
use App\Application\Auth\RequestEmailVerificationEndpointHandler;
use App\Application\Auth\RequestEmailVerificationEndpointResult;
use App\Application\Auth\VerifyEmailEndpointsHandler;
use App\Application\AuthClient\AuthClientAdminEndpointsHandler;
use App\Application\AuthClient\AuthClientDeviceFlowEndpointsHandler;
use App\Application\AuthClient\CancelDeviceFlowEndpointResult;
use App\Application\AuthClient\MintClientTokenEndpointHandler;
use App\Application\AuthClient\MintClientTokenEndpointResult;
use App\Application\AuthClient\PollDeviceFlowEndpointResult;
use App\Application\AuthClient\RevokeClientTokenEndpointResult;
use App\Application\AuthClient\RotateClientSecretEndpointResult;
use App\Application\AuthClient\StartDeviceFlowEndpointResult;
use App\Auth\UserAccessTokenService;
use App\Observability\Repository\MetricEventRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/auth')]
final class AuthController
{
    public function __construct(
        private RequestPasswordResetEndpointHandler $requestPasswordResetEndpointHandler,
        private ResetPasswordEndpointHandler $resetPasswordEndpointHandler,
        private RequestEmailVerificationEndpointHandler $requestEmailVerificationEndpointHandler,
        private VerifyEmailEndpointsHandler $verifyEmailEndpointsHandler,
        private AuthSelfServiceEndpointsHandler $authSelfServiceEndpointsHandler,
        private TranslatorInterface $translator,
        private MintClientTokenEndpointHandler $mintClientTokenEndpointHandler,
        private AuthClientAdminEndpointsHandler $authClientAdminEndpointsHandler,
        private AuthClientDeviceFlowEndpointsHandler $authClientDeviceFlowEndpointsHandler,
        private UserAccessTokenService $userAccessTokenService,
        private MetricEventRepository $metrics,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException('This endpoint is handled by the security authenticator.');
    }

    #[Route('/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($authorization, 'Bearer ')) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $accessToken = trim(substr($authorization, 7));
        if ($accessToken === '' || !$this->userAccessTokenService->revoke($accessToken)) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        return new JsonResponse(['authenticated' => false], Response::HTTP_OK);
    }

    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $result = $this->authSelfServiceEndpointsHandler->me();
        if ($result->status() === AuthMeEndpointResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        return new JsonResponse([
            'id' => $result->id() ?? '',
            'email' => $result->email() ?? '',
            'roles' => $result->roles(),
        ], Response::HTTP_OK);
    }

    #[Route('/2fa/setup', name: 'api_auth_2fa_setup', methods: ['POST'])]
    public function twoFactorSetup(): JsonResponse
    {
        $result = $this->authSelfServiceEndpointsHandler->twoFactorSetup();
        if ($result->status() === TwoFactorSetupEndpointResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        if ($result->status() === TwoFactorSetupEndpointResult::STATUS_ALREADY_ENABLED) {
            return new JsonResponse(
                ['code' => 'MFA_ALREADY_ENABLED', 'message' => $this->translator->trans('auth.error.mfa_already_enabled')],
                Response::HTTP_CONFLICT
            );
        }

        return new JsonResponse($result->setup(), Response::HTTP_OK);
    }

    #[Route('/2fa/enable', name: 'api_auth_2fa_enable', methods: ['POST'])]
    public function twoFactorEnable(Request $request): JsonResponse
    {
        $result = $this->authSelfServiceEndpointsHandler->twoFactorEnable($this->payload($request));
        if ($result->status() === TwoFactorEnableEndpointResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        if ($result->status() === TwoFactorEnableEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.otp_code_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === TwoFactorEnableEndpointResult::STATUS_ALREADY_ENABLED) {
            return new JsonResponse(
                ['code' => 'MFA_ALREADY_ENABLED', 'message' => $this->translator->trans('auth.error.mfa_already_enabled')],
                Response::HTTP_CONFLICT
            );
        }
        if ($result->status() === TwoFactorEnableEndpointResult::STATUS_SETUP_REQUIRED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.mfa_setup_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === TwoFactorEnableEndpointResult::STATUS_INVALID_CODE) {
            return new JsonResponse(
                ['code' => 'INVALID_2FA_CODE', 'message' => $this->translator->trans('auth.error.invalid_2fa_code')],
                Response::HTTP_BAD_REQUEST
            );
        }

        return new JsonResponse([
            'mfa_enabled' => true,
            'recovery_codes' => $result->recoveryCodes(),
        ], Response::HTTP_OK);
    }

    #[Route('/2fa/disable', name: 'api_auth_2fa_disable', methods: ['POST'])]
    public function twoFactorDisable(Request $request): JsonResponse
    {
        $result = $this->authSelfServiceEndpointsHandler->twoFactorDisable($this->payload($request));
        if ($result->status() === TwoFactorDisableEndpointResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        if ($result->status() === TwoFactorDisableEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.otp_code_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === TwoFactorDisableEndpointResult::STATUS_NOT_ENABLED) {
            return new JsonResponse(
                ['code' => 'MFA_NOT_ENABLED', 'message' => $this->translator->trans('auth.error.mfa_not_enabled')],
                Response::HTTP_CONFLICT
            );
        }
        if ($result->status() === TwoFactorDisableEndpointResult::STATUS_INVALID_CODE) {
            return new JsonResponse(
                ['code' => 'INVALID_2FA_CODE', 'message' => $this->translator->trans('auth.error.invalid_2fa_code')],
                Response::HTTP_BAD_REQUEST
            );
        }

        return new JsonResponse(['mfa_enabled' => false], Response::HTTP_OK);
    }

    #[Route('/2fa/recovery-codes/regenerate', name: 'api_auth_2fa_recovery_codes_regenerate', methods: ['POST'])]
    public function regenerateTwoFactorRecoveryCodes(): JsonResponse
    {
        $result = $this->authSelfServiceEndpointsHandler->regenerateTwoFactorRecoveryCodes();
        if ($result->status() === TwoFactorRecoveryCodesEndpointResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        if ($result->status() === TwoFactorRecoveryCodesEndpointResult::STATUS_NOT_ENABLED) {
            return new JsonResponse(
                ['code' => 'MFA_NOT_ENABLED', 'message' => $this->translator->trans('auth.error.mfa_not_enabled')],
                Response::HTTP_CONFLICT
            );
        }

        return new JsonResponse([
            'recovery_codes' => $result->recoveryCodes(),
        ], Response::HTTP_OK);
    }

    #[Route('/me/features', name: 'api_auth_me_features_get', methods: ['GET'])]
    public function meFeatures(): JsonResponse
    {
        $result = $this->authSelfServiceEndpointsHandler->getMyFeatures();
        if ($result->status() === GetMyFeaturesEndpointResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        $features = $result->features();

        return new JsonResponse([
            'user_feature_enabled' => $features?->userFeatureEnabled() ?? [],
            'effective_feature_enabled' => $features?->effectiveFeatureEnabled() ?? [],
            'feature_governance' => $features?->featureGovernance() ?? [],
            'core_v1_global_features' => $features?->coreV1GlobalFeatures() ?? [],
        ], Response::HTTP_OK);
    }

    #[Route('/me/features', name: 'api_auth_me_features_patch', methods: ['PATCH'])]
    public function patchMeFeatures(Request $request): JsonResponse
    {
        $result = $this->authSelfServiceEndpointsHandler->patchMyFeatures($this->payload($request));
        if ($result->status() === PatchMyFeaturesEndpointResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        if ($result->status() === PatchMyFeaturesEndpointResult::STATUS_VALIDATION_FAILED_PAYLOAD) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.invalid_user_feature_payload')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === PatchMyFeaturesEndpointResult::STATUS_FORBIDDEN_SCOPE) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_SCOPE', 'message' => $this->translator->trans('auth.error.forbidden_scope')],
                Response::HTTP_FORBIDDEN
            );
        }
        if ($result->status() === PatchMyFeaturesEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $this->translator->trans('auth.error.invalid_user_feature_payload'),
                    'details' => $result->validationDetails(),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $features = $result->features();

        return new JsonResponse([
            'user_feature_enabled' => $features?->userFeatureEnabled() ?? [],
            'effective_feature_enabled' => $features?->effectiveFeatureEnabled() ?? [],
            'feature_governance' => $features?->featureGovernance() ?? [],
            'core_v1_global_features' => $features?->coreV1GlobalFeatures() ?? [],
        ], Response::HTTP_OK);
    }

    #[Route('/lost-password/request', name: 'api_auth_lost_password_request', methods: ['POST'])]
    public function requestReset(Request $request): JsonResponse
    {
        $result = $this->requestPasswordResetEndpointHandler->handle(
            $this->payload($request),
            (string) ($request->getClientIp() ?? 'unknown')
        );
        if ($result->status() === RequestPasswordResetEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.email_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === RequestPasswordResetEndpointResult::STATUS_TOO_MANY_ATTEMPTS) {
            return new JsonResponse(
                [
                    'code' => 'TOO_MANY_ATTEMPTS',
                    'message' => $this->translator->trans('auth.error.too_many_password_reset_requests'),
                    'retry_in_seconds' => $result->retryInSeconds() ?? 60,
                ],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $response = ['accepted' => true];
        if ($result->token() !== null) {
            $response['reset_token'] = $result->token();
        }

        return new JsonResponse($response, Response::HTTP_ACCEPTED);
    }

    #[Route('/lost-password/reset', name: 'api_auth_lost_password_reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        $result = $this->resetPasswordEndpointHandler->handle($this->payload($request));
        if ($result->status() === ResetPasswordEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $result->violations()[0] ?? $this->translator->trans('auth.error.token_new_password_required'),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($result->status() === ResetPasswordEndpointResult::STATUS_INVALID_TOKEN) {
            return new JsonResponse(
                ['code' => 'INVALID_TOKEN', 'message' => $this->translator->trans('auth.error.invalid_or_expired_token')],
                Response::HTTP_BAD_REQUEST
            );
        }

        return new JsonResponse(['password_reset' => true], Response::HTTP_OK);
    }

    #[Route('/verify-email/request', name: 'api_auth_verify_email_request', methods: ['POST'])]
    public function requestEmailVerification(Request $request): JsonResponse
    {
        $result = $this->requestEmailVerificationEndpointHandler->handle(
            $this->payload($request),
            (string) ($request->getClientIp() ?? 'unknown')
        );
        if ($result->status() === RequestEmailVerificationEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.email_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === RequestEmailVerificationEndpointResult::STATUS_TOO_MANY_ATTEMPTS) {
            return new JsonResponse(
                [
                    'code' => 'TOO_MANY_ATTEMPTS',
                    'message' => $this->translator->trans('auth.error.too_many_verification_requests'),
                    'retry_in_seconds' => $result->retryInSeconds() ?? 60,
                ],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $response = ['accepted' => true];
        if ($result->token() !== null) {
            $response['verification_token'] = $result->token();
        }

        return new JsonResponse($response, Response::HTTP_ACCEPTED);
    }

    #[Route('/verify-email/confirm', name: 'api_auth_verify_email_confirm', methods: ['POST'])]
    public function confirmEmailVerification(Request $request): JsonResponse
    {
        $result = $this->verifyEmailEndpointsHandler->confirm($this->payload($request));
        if ($result->status() === ConfirmEmailVerificationEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.token_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === ConfirmEmailVerificationEndpointResult::STATUS_INVALID_TOKEN) {
            return new JsonResponse(
                ['code' => 'INVALID_TOKEN', 'message' => $this->translator->trans('auth.error.invalid_or_expired_token')],
                Response::HTTP_BAD_REQUEST
            );
        }

        return new JsonResponse(['email_verified' => true], Response::HTTP_OK);
    }

    #[Route('/verify-email/admin-confirm', name: 'api_auth_verify_email_admin_confirm', methods: ['POST'])]
    public function adminConfirmEmailVerification(Request $request): JsonResponse
    {
        $result = $this->verifyEmailEndpointsHandler->adminConfirm($this->payload($request));
        if ($result->status() === AdminConfirmEmailVerificationEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }
        if ($result->status() === AdminConfirmEmailVerificationEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.email_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === AdminConfirmEmailVerificationEndpointResult::STATUS_USER_NOT_FOUND) {
            return new JsonResponse(
                ['code' => 'USER_NOT_FOUND', 'message' => $this->translator->trans('auth.error.unknown_user')],
                Response::HTTP_NOT_FOUND
            );
        }

        return new JsonResponse(['email_verified' => true], Response::HTTP_OK);
    }

    #[Route('/clients/token', name: 'api_auth_clients_token', methods: ['POST'])]
    public function clientToken(Request $request): JsonResponse
    {
        $result = $this->mintClientTokenEndpointHandler->handle(
            $this->payload($request),
            (string) ($request->getClientIp() ?? 'unknown')
        );
        if ($result->status() === MintClientTokenEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.client_credentials_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === MintClientTokenEndpointResult::STATUS_TOO_MANY_ATTEMPTS) {
            return new JsonResponse(
                [
                    'code' => 'TOO_MANY_ATTEMPTS',
                    'message' => $this->translator->trans('auth.error.too_many_client_token_requests'),
                    'retry_in_seconds' => $result->retryInSeconds() ?? 60,
                ],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }
        if ($result->status() === MintClientTokenEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            $this->metrics->record('auth.client.token.forbidden_actor.ui_rust');

            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }
        if ($result->status() === MintClientTokenEndpointResult::STATUS_FORBIDDEN_SCOPE) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_SCOPE', 'message' => $this->translator->trans('auth.error.forbidden_scope')],
                Response::HTTP_FORBIDDEN
            );
        }
        if ($result->status() === MintClientTokenEndpointResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.invalid_client_credentials')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        $token = $result->token();
        if ($result->status() !== MintClientTokenEndpointResult::STATUS_SUCCESS || !is_array($token)) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.invalid_client_credentials')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $this->logger->info('auth.client.token.minted', [
            'client_id' => $result->clientId(),
            'client_kind' => $result->clientKind(),
        ]);

        return new JsonResponse($token, Response::HTTP_OK);
    }

    #[Route('/clients/{clientId}/revoke-token', name: 'api_auth_clients_revoke_token', methods: ['POST'])]
    public function revokeClientToken(string $clientId): JsonResponse
    {
        $result = $this->authClientAdminEndpointsHandler->revoke($clientId);
        if ($result->status() === RevokeClientTokenEndpointResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        if ($result->status() === RevokeClientTokenEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }
        if ($result->status() === RevokeClientTokenEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.invalid_client_id')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($result->status() === RevokeClientTokenEndpointResult::STATUS_FORBIDDEN_SCOPE) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_SCOPE', 'message' => $this->translator->trans('auth.error.forbidden_scope')],
                Response::HTTP_FORBIDDEN
            );
        }

        $this->logger->info('auth.client.token.revoked', [
            'client_id' => $clientId,
            'client_kind' => $result->clientKind(),
        ]);

        return new JsonResponse(['revoked' => true, 'client_id' => $clientId], Response::HTTP_OK);
    }

    #[Route('/clients/{clientId}/rotate-secret', name: 'api_auth_clients_rotate_secret', methods: ['POST'])]
    public function rotateClientSecret(string $clientId): JsonResponse
    {
        $result = $this->authClientAdminEndpointsHandler->rotate($clientId);
        if ($result->status() === RotateClientSecretEndpointResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        if ($result->status() === RotateClientSecretEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }

        if ($result->status() === RotateClientSecretEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.invalid_client_id')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->logger->info('auth.client.secret.rotated', [
            'client_id' => $clientId,
            'client_kind' => $result->clientKind(),
        ]);

        return new JsonResponse(
            [
                'client_id' => $clientId,
                'secret_key' => $result->secretKey(),
                'rotated' => true,
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/clients/device/start', name: 'api_auth_clients_device_start', methods: ['POST'])]
    public function startDeviceFlow(Request $request): JsonResponse
    {
        $result = $this->authClientDeviceFlowEndpointsHandler->start($this->payload($request));
        if ($result->status() === StartDeviceFlowEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.client_kind_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === StartDeviceFlowEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }
        if ($result->status() === StartDeviceFlowEndpointResult::STATUS_FORBIDDEN_SCOPE) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_SCOPE', 'message' => $this->translator->trans('auth.error.forbidden_scope')],
                Response::HTTP_FORBIDDEN
            );
        }

        return new JsonResponse($result->payload(), Response::HTTP_OK);
    }

    #[Route('/clients/device/poll', name: 'api_auth_clients_device_poll', methods: ['POST'])]
    public function pollDeviceFlow(Request $request): JsonResponse
    {
        $result = $this->authClientDeviceFlowEndpointsHandler->poll($this->payload($request));
        if ($result->status() === PollDeviceFlowEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.device_code_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === PollDeviceFlowEndpointResult::STATUS_INVALID_DEVICE_CODE) {
            $this->metrics->record('auth.device.poll.invalid_device_code');

            return new JsonResponse(
                ['code' => 'INVALID_DEVICE_CODE', 'message' => $this->translator->trans('auth.error.invalid_device_code')],
                Response::HTTP_BAD_REQUEST
            );
        }

        $status = $result->payload();
        if ($result->status() === PollDeviceFlowEndpointResult::STATUS_THROTTLED && is_array($status)) {
            $this->metrics->record('auth.device.poll.throttled');

            return new JsonResponse(
                [
                    'code' => 'SLOW_DOWN',
                    'message' => $this->translator->trans('auth.error.slow_down'),
                    'retry_in_seconds' => $result->retryInSeconds() ?? (int) ($status['retry_in_seconds'] ?? 0),
                ],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        if (!is_array($status)) {
            return new JsonResponse(
                ['code' => 'INVALID_DEVICE_CODE', 'message' => $this->translator->trans('auth.error.invalid_device_code')],
                Response::HTTP_BAD_REQUEST
            );
        }

        $flowStatus = strtoupper((string) ($status['status'] ?? ''));
        if (in_array($flowStatus, ['PENDING', 'APPROVED', 'DENIED', 'EXPIRED'], true)) {
            $this->metrics->record(sprintf('auth.device.poll.status.%s', $flowStatus));
            if ($flowStatus === 'APPROVED') {
                $this->logger->info('auth.device.approved');
            }
            if ($flowStatus === 'DENIED') {
                $this->logger->warning('auth.device.denied');
            }
        }

        return new JsonResponse($status, Response::HTTP_OK);
    }

    #[Route('/clients/device/cancel', name: 'api_auth_clients_device_cancel', methods: ['POST'])]
    public function cancelDeviceFlow(Request $request): JsonResponse
    {
        $result = $this->authClientDeviceFlowEndpointsHandler->cancel($this->payload($request));
        if ($result->status() === CancelDeviceFlowEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.device_code_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === CancelDeviceFlowEndpointResult::STATUS_INVALID_DEVICE_CODE) {
            return new JsonResponse(
                ['code' => 'INVALID_DEVICE_CODE', 'message' => $this->translator->trans('auth.error.invalid_device_code')],
                Response::HTTP_BAD_REQUEST
            );
        }
        if ($result->status() === CancelDeviceFlowEndpointResult::STATUS_EXPIRED_DEVICE_CODE) {
            return new JsonResponse(
                ['code' => 'EXPIRED_DEVICE_CODE', 'message' => $this->translator->trans('auth.error.expired_device_code')],
                Response::HTTP_BAD_REQUEST
            );
        }

        return new JsonResponse(['canceled' => true], Response::HTTP_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        if ($request->getContent() === '') {
            return [];
        }

        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
