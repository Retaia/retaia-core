<?php

namespace App\Controller\Api;

use App\Application\Auth\RequestPasswordResetEndpointHandler;
use App\Application\Auth\RequestPasswordResetEndpointResult;
use App\Application\Auth\ResetPasswordEndpointHandler;
use App\Application\Auth\ResetPasswordEndpointResult;
use App\Application\Auth\SetupTwoFactorHandler;
use App\Application\Auth\SetupTwoFactorResult;
use App\Application\Auth\EnableTwoFactorHandler;
use App\Application\Auth\EnableTwoFactorResult;
use App\Application\Auth\DisableTwoFactorHandler;
use App\Application\Auth\DisableTwoFactorResult;
use App\Application\Auth\AdminConfirmEmailVerificationHandler;
use App\Application\Auth\AdminConfirmEmailVerificationResult;
use App\Application\Auth\ConfirmEmailVerificationHandler;
use App\Application\Auth\ConfirmEmailVerificationResult;
use App\Application\Auth\GetMyFeaturesHandler;
use App\Application\Auth\PatchMyFeaturesHandler;
use App\Application\Auth\PatchMyFeaturesResult;
use App\Application\Auth\ResolveAdminActorHandler;
use App\Application\Auth\ResolveAdminActorResult;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\ResolveAuthenticatedUserResult;
use App\Application\Auth\GetAuthMeProfileHandler;
use App\Application\Auth\RequestEmailVerificationEndpointHandler;
use App\Application\Auth\RequestEmailVerificationEndpointResult;
use App\Application\AuthClient\AuthClientAdminEndpointsHandler;
use App\Application\AuthClient\AuthClientDeviceFlowEndpointsHandler;
use App\Application\AuthClient\CancelDeviceFlowEndpointResult;
use App\Application\AuthClient\MintClientTokenHandler;
use App\Application\AuthClient\MintClientTokenResult;
use App\Application\AuthClient\PollDeviceFlowEndpointResult;
use App\Application\AuthClient\RevokeClientTokenEndpointResult;
use App\Application\AuthClient\RotateClientSecretEndpointResult;
use App\Application\AuthClient\StartDeviceFlowEndpointResult;
use App\Observability\Repository\MetricEventRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/auth')]
final class AuthController
{
    public function __construct(
        private RequestPasswordResetEndpointHandler $requestPasswordResetEndpointHandler,
        private ResetPasswordEndpointHandler $resetPasswordEndpointHandler,
        private RequestEmailVerificationEndpointHandler $requestEmailVerificationEndpointHandler,
        private ConfirmEmailVerificationHandler $confirmEmailVerificationHandler,
        private AdminConfirmEmailVerificationHandler $adminConfirmEmailVerificationHandler,
        private SetupTwoFactorHandler $setupTwoFactorHandler,
        private EnableTwoFactorHandler $enableTwoFactorHandler,
        private DisableTwoFactorHandler $disableTwoFactorHandler,
        private GetMyFeaturesHandler $getMyFeaturesHandler,
        private PatchMyFeaturesHandler $patchMyFeaturesHandler,
        private GetAuthMeProfileHandler $getAuthMeProfileHandler,
        private ResolveAdminActorHandler $resolveAdminActorHandler,
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
        private TranslatorInterface $translator,
        #[Autowire(service: 'limiter.client_token_mint')]
        private RateLimiterFactory $clientTokenMintLimiter,
        private MintClientTokenHandler $mintClientTokenHandler,
        private AuthClientAdminEndpointsHandler $authClientAdminEndpointsHandler,
        private AuthClientDeviceFlowEndpointsHandler $authClientDeviceFlowEndpointsHandler,
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
    public function logout(): JsonResponse
    {
        throw new \LogicException('This endpoint is handled by the firewall logout.');
    }

    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $result = $this->getAuthMeProfileHandler->handle(
            (string) $authenticatedUser->id(),
            (string) $authenticatedUser->email(),
            $authenticatedUser->roles()
        );

        return new JsonResponse([
            'id' => $result->id(),
            'email' => $result->email(),
            'roles' => $result->roles(),
        ], Response::HTTP_OK);
    }

    #[Route('/2fa/setup', name: 'api_auth_2fa_setup', methods: ['POST'])]
    public function twoFactorSetup(): JsonResponse
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $result = $this->setupTwoFactorHandler->handle((string) $authenticatedUser->id(), (string) $authenticatedUser->email());
        if ($result->status() === SetupTwoFactorResult::STATUS_ALREADY_ENABLED) {
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
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $otpCode = trim((string) ($this->payload($request)['otp_code'] ?? ''));
        if ($otpCode === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.otp_code_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->enableTwoFactorHandler->handle((string) $authenticatedUser->id(), $otpCode);
        if ($result->status() === EnableTwoFactorResult::STATUS_ALREADY_ENABLED) {
            return new JsonResponse(
                ['code' => 'MFA_ALREADY_ENABLED', 'message' => $this->translator->trans('auth.error.mfa_already_enabled')],
                Response::HTTP_CONFLICT
            );
        }
        if ($result->status() === EnableTwoFactorResult::STATUS_SETUP_REQUIRED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.mfa_setup_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === EnableTwoFactorResult::STATUS_INVALID_CODE) {
            return new JsonResponse(
                ['code' => 'INVALID_2FA_CODE', 'message' => $this->translator->trans('auth.error.invalid_2fa_code')],
                Response::HTTP_BAD_REQUEST
            );
        }

        return new JsonResponse(['mfa_enabled' => true], Response::HTTP_OK);
    }

    #[Route('/2fa/disable', name: 'api_auth_2fa_disable', methods: ['POST'])]
    public function twoFactorDisable(Request $request): JsonResponse
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $otpCode = trim((string) ($this->payload($request)['otp_code'] ?? ''));
        if ($otpCode === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.otp_code_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->disableTwoFactorHandler->handle((string) $authenticatedUser->id(), $otpCode);
        if ($result->status() === DisableTwoFactorResult::STATUS_NOT_ENABLED) {
            return new JsonResponse(
                ['code' => 'MFA_NOT_ENABLED', 'message' => $this->translator->trans('auth.error.mfa_not_enabled')],
                Response::HTTP_CONFLICT
            );
        }
        if ($result->status() === DisableTwoFactorResult::STATUS_INVALID_CODE) {
            return new JsonResponse(
                ['code' => 'INVALID_2FA_CODE', 'message' => $this->translator->trans('auth.error.invalid_2fa_code')],
                Response::HTTP_BAD_REQUEST
            );
        }

        return new JsonResponse(['mfa_enabled' => false], Response::HTTP_OK);
    }

    #[Route('/me/features', name: 'api_auth_me_features_get', methods: ['GET'])]
    public function meFeatures(): JsonResponse
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $result = $this->getMyFeaturesHandler->handle((string) $authenticatedUser->id());

        return new JsonResponse([
            'user_feature_enabled' => $result->userFeatureEnabled(),
            'effective_feature_enabled' => $result->effectiveFeatureEnabled(),
            'feature_governance' => $result->featureGovernance(),
            'core_v1_global_features' => $result->coreV1GlobalFeatures(),
        ], Response::HTTP_OK);
    }

    #[Route('/me/features', name: 'api_auth_me_features_patch', methods: ['PATCH'])]
    public function patchMeFeatures(Request $request): JsonResponse
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $payload = $this->payload($request);
        $rawUserFeatures = $payload['user_feature_enabled'] ?? null;
        if (!is_array($rawUserFeatures)) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.invalid_user_feature_payload')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->patchMyFeaturesHandler->handle((string) $authenticatedUser->id(), $rawUserFeatures);
        if ($result->status() === PatchMyFeaturesResult::STATUS_FORBIDDEN_SCOPE) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_SCOPE', 'message' => $this->translator->trans('auth.error.forbidden_scope')],
                Response::HTTP_FORBIDDEN
            );
        }
        if ($result->status() === PatchMyFeaturesResult::STATUS_VALIDATION_FAILED) {
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
        $payload = $this->payload($request);
        $token = trim((string) ($payload['token'] ?? ''));
        if ($token === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.token_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->confirmEmailVerificationHandler->handle($token);
        if ($result->status() === ConfirmEmailVerificationResult::STATUS_INVALID_TOKEN) {
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
        $adminActor = $this->resolveAdminActorHandler->handle();
        if ($adminActor->status() === ResolveAdminActorResult::STATUS_FORBIDDEN_ACTOR) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }

        $payload = $this->payload($request);
        $email = trim((string) ($payload['email'] ?? ''));
        if ($email === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.email_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $actorId = $adminActor->actorId();

        $result = $this->adminConfirmEmailVerificationHandler->handle($email, $actorId);
        if ($result->status() === AdminConfirmEmailVerificationResult::STATUS_USER_NOT_FOUND) {
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
        $payload = $this->payload($request);
        $clientId = trim((string) ($payload['client_id'] ?? ''));
        $clientKind = trim((string) ($payload['client_kind'] ?? ''));
        $secretKey = trim((string) ($payload['secret_key'] ?? ''));

        if ($clientId === '' || $clientKind === '' || $secretKey === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.client_credentials_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $limiterKey = hash('sha256', mb_strtolower($clientId).'|'.$clientKind.'|'.(string) ($request->getClientIp() ?? 'unknown'));
        $limit = $this->clientTokenMintLimiter->create($limiterKey)->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter();

            return new JsonResponse(
                [
                    'code' => 'TOO_MANY_ATTEMPTS',
                    'message' => $this->translator->trans('auth.error.too_many_client_token_requests'),
                    'retry_in_seconds' => $retryAfter !== null ? max(1, $retryAfter->getTimestamp() - time()) : 60,
                ],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $result = $this->mintClientTokenHandler->handle($clientId, $clientKind, $secretKey);
        if ($result->status() === MintClientTokenResult::STATUS_FORBIDDEN_ACTOR) {
            $this->metrics->record('auth.client.token.forbidden_actor.ui_rust');

            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }
        if ($result->status() === MintClientTokenResult::STATUS_FORBIDDEN_SCOPE) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_SCOPE', 'message' => $this->translator->trans('auth.error.forbidden_scope')],
                Response::HTTP_FORBIDDEN
            );
        }
        if ($result->status() === MintClientTokenResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.invalid_client_credentials')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        $token = $result->token();
        if (!is_array($token)) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.invalid_client_credentials')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $this->logger->info('auth.client.token.minted', [
            'client_id' => $clientId,
            'client_kind' => $clientKind,
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
