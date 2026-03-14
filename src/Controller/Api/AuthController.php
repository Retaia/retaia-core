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
use App\Controller\RequestPayloadTrait;
use App\Domain\AuthClient\ClientKind;
use App\Domain\AuthClient\DeviceFlowStatus;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use App\Observability\MetricName;
use App\Observability\Repository\MetricEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
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
    use RequestPayloadTrait;

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
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        private Connection $connection,
        #[Autowire(service: 'limiter.webauthn_authenticate')]
        private RateLimiterFactory $webauthnAuthenticateRateLimiter,
        private EntityManagerInterface $entityManager,
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

    #[Route('/webauthn/register/options', name: 'api_auth_webauthn_register_options', methods: ['POST'])]
    public function webauthnRegisterOptions(Request $request): JsonResponse
    {
        $result = $this->authSelfServiceEndpointsHandler->me();
        if ($result->status() === AuthMeEndpointResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $userId = trim((string) ($result->id() ?? ''));
        $userEmail = trim((string) ($result->email() ?? ''));
        if ($userId === '' || $userEmail === '') {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $requestId = bin2hex(random_bytes(16));
        $challenge = $this->base64UrlEncode(random_bytes(32));
        $cacheItem = $this->cache->getItem($this->registerRequestCacheKey($requestId));
        $cacheItem->set([
            'user_id' => $userId,
            'email' => $userEmail,
            'challenge' => $challenge,
            'expires_at' => time() + 300,
            'used' => false,
        ]);
        $cacheItem->expiresAfter(300);
        $this->cache->save($cacheItem);

        return new JsonResponse([
            'request_id' => $requestId,
            'public_key' => [
                'challenge' => $challenge,
                'rp' => [
                    'name' => 'Retaia',
                    'id' => $this->relyingPartyId($request),
                ],
                'user' => [
                    'id' => $this->base64UrlEncode($userId),
                    'name' => $userEmail,
                    'displayName' => $userEmail,
                ],
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],
                    ['type' => 'public-key', 'alg' => -257],
                ],
                'timeout' => 300000,
                'attestation' => 'none',
            ],
        ], Response::HTTP_OK);
    }

    #[Route('/webauthn/register/verify', name: 'api_auth_webauthn_register_verify', methods: ['POST'])]
    public function webauthnRegisterVerify(Request $request): JsonResponse
    {
        $me = $this->authSelfServiceEndpointsHandler->me();
        if ($me->status() === AuthMeEndpointResult::STATUS_UNAUTHORIZED) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $payload = $this->payload($request);
        if (!is_array($payload['credential'] ?? null)) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.invalid_user_feature_payload')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $requestId = trim((string) ($payload['request_id'] ?? ($payload['credential']['request_id'] ?? '')));
        if ($requestId === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.invalid_user_feature_payload')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $cacheItem = $this->cache->getItem($this->registerRequestCacheKey($requestId));
        $state = $cacheItem->get();
        if (
            !is_array($state)
            || ($state['used'] ?? true) !== false
            || !is_int($state['expires_at'] ?? null)
            || (int) $state['expires_at'] < time()
            || !hash_equals((string) ($state['user_id'] ?? ''), (string) ($me->id() ?? ''))
        ) {
            return new JsonResponse(
                ['code' => 'STATE_CONFLICT', 'message' => $this->translator->trans('auth.error.state_conflict')],
                Response::HTTP_CONFLICT
            );
        }

        $state['used'] = true;
        $cacheItem->set($state);
        $this->cache->save($cacheItem);

        $deviceId = $this->normalizeUuid((string) ($payload['device_id'] ?? ''));
        $deviceLabel = trim((string) ($payload['device_label'] ?? 'Passkey'));
        if ($deviceLabel === '') {
            $deviceLabel = 'Passkey';
        }
        $credentialId = trim((string) ($payload['credential']['id'] ?? ''));
        if ($credentialId === '') {
            $credentialId = $deviceId;
        }

        $fingerprintSource = json_encode($payload['credential'], JSON_UNESCAPED_SLASHES);
        $fingerprint = hash('sha256', is_string($fingerprintSource) ? $fingerprintSource : '');
        $this->storeWebauthnDevice((string) $me->id(), [
            'device_id' => $deviceId,
            'device_label' => $deviceLabel,
            'credential_id' => $credentialId,
            'webauthn_fingerprint' => $fingerprint,
        ]);

        return new JsonResponse([
            'device_id' => $deviceId,
            'device_label' => $deviceLabel,
            'webauthn_fingerprint' => $fingerprint,
        ], Response::HTTP_OK);
    }

    #[Route('/webauthn/authenticate/options', name: 'api_auth_webauthn_authenticate_options', methods: ['POST'])]
    public function webauthnAuthenticateOptions(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $email = trim((string) ($payload['email'] ?? ''));
        $remoteAddress = (string) ($request->getClientIp() ?? 'unknown');
        $rateLimitKey = $email !== '' ? mb_strtolower($email).'|'.$remoteAddress : 'anonymous|'.$remoteAddress;
        $throttled = $this->consumeWebauthnAuthenticateRateLimit($rateLimitKey);
        if ($throttled instanceof JsonResponse) {
            return $throttled;
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.invalid_user_feature_payload')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $clientId = trim((string) ($payload['client_id'] ?? 'interactive-default'));
        if ($clientId === '') {
            $clientId = 'interactive-default';
        }
        $clientKind = trim((string) ($payload['client_kind'] ?? ClientKind::UI_WEB));
        if (!ClientKind::isInteractive($clientKind)) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.client_kind_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $requestId = bin2hex(random_bytes(16));
        $challenge = $this->base64UrlEncode(random_bytes(32));
        $cacheItem = $this->cache->getItem($this->authenticateRequestCacheKey($requestId));
        $cacheItem->set([
            'email' => $email !== '' ? mb_strtolower($email) : null,
            'client_id' => $clientId,
            'client_kind' => $clientKind,
            'expires_at' => time() + 300,
            'used' => false,
        ]);
        $cacheItem->expiresAfter(300);
        $this->cache->save($cacheItem);

        $allowCredentials = [];
        if ($email !== '') {
            $user = $this->findUserByEmail($email);
            if ($user instanceof User) {
                $devices = $this->loadWebauthnDevices($user->getId());
                foreach ($devices as $device) {
                    $allowCredentials[] = [
                        'type' => 'public-key',
                        'id' => (string) ($device['credential_id'] ?? ''),
                    ];
                }
            }
        }

        return new JsonResponse([
            'request_id' => $requestId,
            'public_key' => [
                'challenge' => $challenge,
                'timeout' => 300000,
                'rpId' => $this->relyingPartyId($request),
                'allowCredentials' => $allowCredentials,
                'userVerification' => 'preferred',
            ],
        ], Response::HTTP_OK);
    }

    #[Route('/webauthn/authenticate/verify', name: 'api_auth_webauthn_authenticate_verify', methods: ['POST'])]
    public function webauthnAuthenticateVerify(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $remoteAddress = (string) ($request->getClientIp() ?? 'unknown');
        $throttled = $this->consumeWebauthnAuthenticateRateLimit('verify|'.$remoteAddress);
        if ($throttled instanceof JsonResponse) {
            return $throttled;
        }

        if (!is_array($payload['credential'] ?? null)) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.invalid_user_feature_payload')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $requestId = trim((string) ($payload['request_id'] ?? ($payload['credential']['request_id'] ?? '')));
        if ($requestId === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.invalid_user_feature_payload')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $cacheItem = $this->cache->getItem($this->authenticateRequestCacheKey($requestId));
        $state = $cacheItem->get();
        if (
            !is_array($state)
            || ($state['used'] ?? true) !== false
            || !is_int($state['expires_at'] ?? null)
            || (int) $state['expires_at'] < time()
        ) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.invalid_credentials')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $email = trim((string) ($state['email'] ?? ''));
        if ($email === '') {
            $email = trim((string) (($payload['credential']['email'] ?? $payload['credential']['user']['email'] ?? '')));
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.invalid_credentials')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $user = $this->findUserByEmail($email);
        if (!$user instanceof User || !$user->isEmailVerified()) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.invalid_credentials')],
                Response::HTTP_UNAUTHORIZED
            );
        }
        $credentialId = trim((string) ($payload['credential']['id'] ?? ''));
        if ($credentialId !== '') {
            $userDevices = $this->loadWebauthnDevices($user->getId());
            if ($userDevices !== []) {
                $knownCredential = false;
                foreach ($userDevices as $device) {
                    if (hash_equals((string) ($device['credential_id'] ?? ''), $credentialId)) {
                        $knownCredential = true;
                        break;
                    }
                }
                if (!$knownCredential) {
                    return new JsonResponse(
                        ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.invalid_credentials')],
                        Response::HTTP_UNAUTHORIZED
                    );
                }
            }
        }

        $clientId = trim((string) ($payload['client_id'] ?? ($state['client_id'] ?? 'interactive-default')));
        if ($clientId === '') {
            $clientId = 'interactive-default';
        }
        $clientKind = trim((string) ($payload['client_kind'] ?? ($state['client_kind'] ?? ClientKind::UI_WEB)));
        if (!ClientKind::isInteractive($clientKind)) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.client_kind_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $state['used'] = true;
        $cacheItem->set($state);
        $this->cache->save($cacheItem);

        return new JsonResponse(
            $this->userAccessTokenService->issue($user, $clientId, $clientKind),
            Response::HTTP_OK
        );
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
            $this->metrics->record(MetricName::AUTH_CLIENT_TOKEN_FORBIDDEN_ACTOR_UI_WEB);

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
        $result = $this->authClientDeviceFlowEndpointsHandler->start(
            $this->payload($request),
            (string) ($request->getClientIp() ?? 'unknown')
        );
        if ($result->status() === StartDeviceFlowEndpointResult::STATUS_VALIDATION_FAILED) {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.client_kind_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        if ($result->status() === StartDeviceFlowEndpointResult::STATUS_TOO_MANY_ATTEMPTS) {
            return new JsonResponse(
                [
                    'code' => 'TOO_MANY_ATTEMPTS',
                    'message' => $this->translator->trans('auth.error.too_many_client_token_requests'),
                    'retry_in_seconds' => $result->retryInSeconds() ?? 60,
                ],
                Response::HTTP_TOO_MANY_REQUESTS
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
            $this->metrics->record(MetricName::AUTH_DEVICE_POLL_INVALID_DEVICE_CODE);

            return new JsonResponse(
                ['code' => 'INVALID_DEVICE_CODE', 'message' => $this->translator->trans('auth.error.invalid_device_code')],
                Response::HTTP_BAD_REQUEST
            );
        }

        $status = $result->payload();
        if ($result->status() === PollDeviceFlowEndpointResult::STATUS_THROTTLED && is_array($status)) {
            $this->metrics->record(MetricName::AUTH_DEVICE_POLL_THROTTLED);

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
        if (DeviceFlowStatus::isKnown($flowStatus)) {
            $this->metrics->record(MetricName::authDevicePollStatus($flowStatus));
            if ($flowStatus === DeviceFlowStatus::APPROVED) {
                $this->logger->info('auth.device.approved');
            }
            if ($flowStatus === DeviceFlowStatus::DENIED) {
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

    private function registerRequestCacheKey(string $requestId): string
    {
        return 'webauthn.register.request.'.$requestId;
    }

    private function authenticateRequestCacheKey(string $requestId): string
    {
        return 'webauthn.authenticate.request.'.$requestId;
    }

    private function devicesCacheKey(string $userId): string
    {
        return 'webauthn.devices.'.$userId;
    }

    private function relyingPartyId(Request $request): string
    {
        $host = $request->getHost();
        if ($host === '') {
            return 'localhost';
        }

        return $host;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function normalizeUuid(string $value): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1) {
            return strtolower($value);
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * @param array<string, string> $device
     */
    private function storeWebauthnDevice(string $userId, array $device): void
    {
        $credentialId = (string) ($device['credential_id'] ?? '');
        if ($credentialId === '') {
            return;
        }

        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM webauthn_device WHERE user_id = :userId AND credential_id = :credentialId',
            ['userId' => $userId, 'credentialId' => $credentialId]
        );
        if ($exists !== false) {
            return;
        }

        $this->connection->insert('webauthn_device', [
            'id' => (string) ($device['device_id'] ?? $this->normalizeUuid('')),
            'user_id' => $userId,
            'credential_id' => $credentialId,
            'device_label' => (string) ($device['device_label'] ?? 'Passkey'),
            'webauthn_fingerprint' => (string) ($device['webauthn_fingerprint'] ?? ''),
            'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function loadWebauthnDevices(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id AS device_id, credential_id, device_label, webauthn_fingerprint FROM webauthn_device WHERE user_id = :userId ORDER BY created_at ASC',
            ['userId' => $userId]
        );

        return array_map(
            static fn (array $row): array => [
                'device_id' => (string) ($row['device_id'] ?? ''),
                'credential_id' => (string) ($row['credential_id'] ?? ''),
                'device_label' => (string) ($row['device_label'] ?? ''),
                'webauthn_fingerprint' => (string) ($row['webauthn_fingerprint'] ?? ''),
            ],
            $rows
        );
    }

    private function findUserByEmail(string $email): ?User
    {
        $normalized = mb_strtolower(trim($email));
        if ($normalized === '') {
            return null;
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $normalized]);

        return $user instanceof User ? $user : null;
    }

    private function consumeWebauthnAuthenticateRateLimit(string $key): ?JsonResponse
    {
        $limit = $this->webauthnAuthenticateRateLimiter->create($key)->consume(1);
        if ($limit->isAccepted()) {
            return null;
        }

        $retryAfter = $limit->getRetryAfter();
        $retryInSeconds = max(
            1,
            $retryAfter instanceof \DateTimeImmutable ? $retryAfter->getTimestamp() - time() : 1
        );

        return new JsonResponse(
            [
                'code' => 'TOO_MANY_ATTEMPTS',
                'message' => $this->translator->trans('auth.error.too_many_client_token_requests'),
                'retry_in_seconds' => $retryInSeconds,
            ],
            Response::HTTP_TOO_MANY_REQUESTS
        );
    }

}
