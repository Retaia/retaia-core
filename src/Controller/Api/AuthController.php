<?php

namespace App\Controller\Api;

use App\Application\AuthClient\MintClientTokenHandler;
use App\Application\AuthClient\MintClientTokenResult;
use App\Application\AuthClient\CancelDeviceFlowHandler;
use App\Application\AuthClient\CancelDeviceFlowResult;
use App\Application\AuthClient\PollDeviceFlowHandler;
use App\Application\AuthClient\PollDeviceFlowResult;
use App\Application\AuthClient\RevokeClientTokenHandler;
use App\Application\AuthClient\RevokeClientTokenResult;
use App\Application\AuthClient\RotateClientSecretHandler;
use App\Application\AuthClient\RotateClientSecretResult;
use App\Application\AuthClient\StartDeviceFlowHandler;
use App\Application\AuthClient\StartDeviceFlowResult;
use App\Auth\AuthClientService;
use App\Entity\User;
use App\Feature\FeatureGovernanceService;
use App\Observability\Repository\MetricEventRepository;
use App\User\Service\EmailVerificationService;
use App\User\Service\PasswordPolicy;
use App\User\Service\PasswordResetService;
use App\User\Service\TwoFactorService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/auth')]
final class AuthController
{
    public function __construct(
        private Security $security,
        private PasswordResetService $passwordResetService,
        private EmailVerificationService $emailVerificationService,
        private TwoFactorService $twoFactorService,
        private FeatureGovernanceService $featureGovernanceService,
        private PasswordPolicy $passwordPolicy,
        private TranslatorInterface $translator,
        #[Autowire(service: 'limiter.lost_password_request')]
        private RateLimiterFactory $lostPasswordRequestLimiter,
        #[Autowire(service: 'limiter.verify_email_request')]
        private RateLimiterFactory $verifyEmailRequestLimiter,
        #[Autowire(service: 'limiter.client_token_mint')]
        private RateLimiterFactory $clientTokenMintLimiter,
        private AuthClientService $authClientService,
        private MintClientTokenHandler $mintClientTokenHandler,
        private RevokeClientTokenHandler $revokeClientTokenHandler,
        private RotateClientSecretHandler $rotateClientSecretHandler,
        private StartDeviceFlowHandler $startDeviceFlowHandler,
        private PollDeviceFlowHandler $pollDeviceFlowHandler,
        private CancelDeviceFlowHandler $cancelDeviceFlowHandler,
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
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        return new JsonResponse(
            [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/2fa/setup', name: 'api_auth_2fa_setup', methods: ['POST'])]
    public function twoFactorSetup(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        try {
            $setup = $this->twoFactorService->setup($user->getId(), $user->getEmail());
        } catch (\RuntimeException) {
            return new JsonResponse(
                ['code' => 'MFA_ALREADY_ENABLED', 'message' => $this->translator->trans('auth.error.mfa_already_enabled')],
                Response::HTTP_CONFLICT
            );
        }

        return new JsonResponse($setup, Response::HTTP_OK);
    }

    #[Route('/2fa/enable', name: 'api_auth_2fa_enable', methods: ['POST'])]
    public function twoFactorEnable(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
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

        try {
            $enabled = $this->twoFactorService->enable($user->getId(), $otpCode);
        } catch (\RuntimeException $exception) {
            $code = $exception->getMessage();
            if ($code === 'MFA_ALREADY_ENABLED') {
                return new JsonResponse(
                    ['code' => 'MFA_ALREADY_ENABLED', 'message' => $this->translator->trans('auth.error.mfa_already_enabled')],
                    Response::HTTP_CONFLICT
                );
            }

            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.mfa_setup_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (!$enabled) {
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
        $user = $this->security->getUser();
        if (!$user instanceof User) {
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

        try {
            $disabled = $this->twoFactorService->disable($user->getId(), $otpCode);
        } catch (\RuntimeException) {
            return new JsonResponse(
                ['code' => 'MFA_NOT_ENABLED', 'message' => $this->translator->trans('auth.error.mfa_not_enabled')],
                Response::HTTP_CONFLICT
            );
        }

        if (!$disabled) {
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
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        return new JsonResponse(
            [
                'user_feature_enabled' => $this->featureGovernanceService->userFeatureEnabled($user->getId()),
                'effective_feature_enabled' => $this->featureGovernanceService->effectiveFeatureEnabledForUser($user->getId()),
                'feature_governance' => $this->featureGovernanceService->featureGovernanceRules(),
                'core_v1_global_features' => $this->featureGovernanceService->coreV1GlobalFeatures(),
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/me/features', name: 'api_auth_me_features_patch', methods: ['PATCH'])]
    public function patchMeFeatures(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
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

        foreach ($rawUserFeatures as $featureKey => $enabled) {
            if (!is_string($featureKey)) {
                continue;
            }
            if (in_array($featureKey, $this->featureGovernanceService->coreV1GlobalFeatures(), true)) {
                return new JsonResponse(
                    ['code' => 'FORBIDDEN_SCOPE', 'message' => $this->translator->trans('auth.error.forbidden_scope')],
                    Response::HTTP_FORBIDDEN
                );
            }
        }

        $validation = $this->featureGovernanceService->validateFeaturePayload(
            $rawUserFeatures,
            $this->featureGovernanceService->allowedUserFeatureKeys()
        );
        if ($validation['unknown_keys'] !== [] || $validation['non_boolean_keys'] !== []) {
            return new JsonResponse(
                [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $this->translator->trans('auth.error.invalid_user_feature_payload'),
                    'details' => $validation,
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->featureGovernanceService->setUserFeatureEnabled($user->getId(), $rawUserFeatures);

        return new JsonResponse(
            [
                'user_feature_enabled' => $this->featureGovernanceService->userFeatureEnabled($user->getId()),
                'effective_feature_enabled' => $this->featureGovernanceService->effectiveFeatureEnabledForUser($user->getId()),
                'feature_governance' => $this->featureGovernanceService->featureGovernanceRules(),
                'core_v1_global_features' => $this->featureGovernanceService->coreV1GlobalFeatures(),
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/lost-password/request', name: 'api_auth_lost_password_request', methods: ['POST'])]
    public function requestReset(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $email = trim((string) ($payload['email'] ?? ''));
        if ($email === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.email_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $remoteAddress = (string) ($request->getClientIp() ?? 'unknown');
        $limiterKey = hash('sha256', mb_strtolower($email).'|'.$remoteAddress);
        $limit = $this->lostPasswordRequestLimiter->create($limiterKey)->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter();

            return new JsonResponse(
                [
                    'code' => 'TOO_MANY_ATTEMPTS',
                    'message' => $this->translator->trans('auth.error.too_many_password_reset_requests'),
                    'retry_in_seconds' => $retryAfter !== null ? max(1, $retryAfter->getTimestamp() - time()) : 60,
                ],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $token = $this->passwordResetService->requestReset($email);
        $response = ['accepted' => true];
        if ($token !== null) {
            $response['reset_token'] = $token;
        }

        return new JsonResponse($response, Response::HTTP_ACCEPTED);
    }

    #[Route('/lost-password/reset', name: 'api_auth_lost_password_reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $token = trim((string) ($payload['token'] ?? ''));
        $newPassword = (string) ($payload['new_password'] ?? '');

        if ($token === '' || $newPassword === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.token_new_password_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $violations = $this->passwordPolicy->violations($newPassword);
        if ($violations !== []) {
            return new JsonResponse(
                [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $violations[0],
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (!$this->passwordResetService->resetPassword($token, $newPassword)) {
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
        $payload = $this->payload($request);
        $email = trim((string) ($payload['email'] ?? ''));
        if ($email === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.email_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $remoteAddress = (string) ($request->getClientIp() ?? 'unknown');
        $limiterKey = hash('sha256', mb_strtolower($email).'|'.$remoteAddress);
        $limit = $this->verifyEmailRequestLimiter->create($limiterKey)->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter();

            return new JsonResponse(
                [
                    'code' => 'TOO_MANY_ATTEMPTS',
                    'message' => $this->translator->trans('auth.error.too_many_verification_requests'),
                    'retry_in_seconds' => $retryAfter !== null ? max(1, $retryAfter->getTimestamp() - time()) : 60,
                ],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $token = $this->emailVerificationService->requestVerification($email);
        $response = ['accepted' => true];
        if ($token !== null) {
            $response['verification_token'] = $token;
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

        if (!$this->emailVerificationService->confirmVerification($token)) {
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
        if (!$this->security->isGranted('ROLE_ADMIN')) {
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

        $actor = $this->security->getUser();
        $actorId = $actor instanceof User ? $actor->getId() : null;

        if (!$this->emailVerificationService->forceVerifyByEmail($email, $actorId)) {
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
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }

        $result = $this->revokeClientTokenHandler->handle($clientId);
        if ($result->status() === RevokeClientTokenResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.invalid_client_id')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($result->status() === RevokeClientTokenResult::STATUS_FORBIDDEN_SCOPE) {
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
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }

        $result = $this->rotateClientSecretHandler->handle($clientId);
        if ($result->status() === RotateClientSecretResult::STATUS_VALIDATION_FAILED) {
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
        $payload = $this->payload($request);
        $clientKind = trim((string) ($payload['client_kind'] ?? ''));

        if ($clientKind === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.client_kind_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->startDeviceFlowHandler->handle($clientKind);
        if ($result->status() === StartDeviceFlowResult::STATUS_FORBIDDEN_ACTOR) {
            return new JsonResponse(
                ['code' => 'FORBIDDEN_ACTOR', 'message' => $this->translator->trans('auth.error.forbidden_actor')],
                Response::HTTP_FORBIDDEN
            );
        }
        if ($result->status() === StartDeviceFlowResult::STATUS_FORBIDDEN_SCOPE) {
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
        $payload = $this->payload($request);
        $deviceCode = trim((string) ($payload['device_code'] ?? ''));
        if ($deviceCode === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.device_code_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->pollDeviceFlowHandler->handle($deviceCode);
        if ($result->status() === PollDeviceFlowResult::STATUS_INVALID_DEVICE_CODE) {
            $this->metrics->record('auth.device.poll.invalid_device_code');

            return new JsonResponse(
                ['code' => 'INVALID_DEVICE_CODE', 'message' => $this->translator->trans('auth.error.invalid_device_code')],
                Response::HTTP_BAD_REQUEST
            );
        }

        $status = $result->payload();
        if ($result->status() === PollDeviceFlowResult::STATUS_THROTTLED && is_array($status)) {
            $this->metrics->record('auth.device.poll.throttled');

            return new JsonResponse(
                [
                    'code' => 'SLOW_DOWN',
                    'message' => $this->translator->trans('auth.error.slow_down'),
                    'retry_in_seconds' => $status['retry_in_seconds'],
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
        $payload = $this->payload($request);
        $deviceCode = trim((string) ($payload['device_code'] ?? ''));
        if ($deviceCode === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.device_code_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->cancelDeviceFlowHandler->handle($deviceCode);
        if ($result->status() === CancelDeviceFlowResult::STATUS_INVALID_DEVICE_CODE) {
            return new JsonResponse(
                ['code' => 'INVALID_DEVICE_CODE', 'message' => $this->translator->trans('auth.error.invalid_device_code')],
                Response::HTTP_BAD_REQUEST
            );
        }
        if ($result->status() === CancelDeviceFlowResult::STATUS_EXPIRED_DEVICE_CODE) {
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
