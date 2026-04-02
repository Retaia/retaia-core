<?php

namespace App\Tests\Unit\Controller;

use App\Api\Service\AgentRuntimeRepositoryInterface;
use App\Api\Service\AgentSignature\AgentPublicKeyRepositoryInterface;
use App\Api\Service\AgentSignature\AgentRequestSignatureVerifier;
use App\Api\Service\AgentSignature\AgentSignatureNonceRepositoryInterface;
use App\Api\Service\AgentSignature\SignedAgentMessageCanonicalizer;
use App\Api\Service\IdempotencyService;
use App\Api\Service\SignedAgentRequestValidator;
use App\Application\Agent\RegisterAgentEndpointHandler;
use App\Application\Agent\RegisterAgentResult;
use App\Application\Agent\RegisterAgentUseCase;
use App\Application\AppPolicy\AppPolicyEndpointsHandler;
use App\Application\AppPolicy\GetAppFeaturesHandler;
use App\Application\AppPolicy\GetAppPolicyHandler;
use App\Application\AppPolicy\PatchAppFeaturesHandler;
use App\Application\AppPolicy\Port\AppFeatureGovernanceGateway;
use App\Application\Auth\AuthSelfServiceEndpointsHandler;
use App\Application\Auth\AuthSelfServiceProfileEndpointsHandler;
use App\Application\Auth\AuthSelfServiceTwoFactorEndpointsHandler;
use App\Application\Auth\DisableTwoFactorHandler;
use App\Application\Auth\EnableTwoFactorHandler;
use App\Application\Auth\GetAuthMeProfileHandler;
use App\Application\Auth\GetMyFeaturesHandler;
use App\Application\Auth\PatchMyFeaturesHandler;
use App\Application\Auth\Port\AdminActorGateway;
use App\Application\Auth\Port\AuthenticatedClientGateway;
use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\Port\FeatureGovernanceGateway;
use App\Application\Auth\Port\TwoFactorGateway;
use App\Application\Auth\RegenerateTwoFactorRecoveryCodesHandler;
use App\Application\Auth\ResolveAdminActorHandler;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\SetupTwoFactorHandler;
use App\Application\AuthClient\ApproveDeviceFlowHandler;
use App\Application\AuthClient\CompleteDeviceApprovalHandler;
use App\Application\AuthClient\Port\DeviceApprovalSecondFactorGateway;
use App\Application\AuthClient\Port\DeviceFlowGateway;
use App\Application\Job\CheckSuggestTagsSubmitScopeHandler;
use App\Application\Job\ClaimJobHandler;
use App\Application\Job\FailJobHandler;
use App\Application\Job\HeartbeatJobHandler;
use App\Application\Job\JobContractPolicy;
use App\Application\Job\JobEndpointsHandler;
use App\Application\Job\ListClaimableJobsHandler;
use App\Application\Job\Port\JobGateway;
use App\Application\Job\ResolveJobLockConflictCodeHandler;
use App\Application\Job\SubmitJobAssetMutator;
use App\Application\Job\SubmitJobDerivedPersister;
use App\Application\Job\SubmitJobHandler;
use App\Application\Job\SubmitJobResultValidator;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Auth\UserAccessJwtService;
use App\Auth\UserAccessTokenService;
use App\Auth\UserAuthSessionRepositoryInterface;
use App\Auth\UserAuthSessionService;
use App\Controller\Api\AgentController;
use App\Controller\Api\AppController;
use App\Controller\Api\AuthApiErrorResponder;
use App\Controller\Api\AuthCurrentSessionResolver;
use App\Controller\Api\AuthProfileHttpResponder;
use App\Controller\Api\AuthRateLimitGuard;
use App\Controller\Api\AuthRecoveryHttpResponder;
use App\Controller\Api\AuthSessionController;
use App\Controller\Api\AuthSessionHttpResponder;
use App\Controller\Api\AuthTwoFactorHttpResponder;
use App\Controller\Api\AuthTwoFactorController;
use App\Controller\Api\AuthProfileController;
use App\Controller\Api\AuthRecoveryController;
use App\Controller\Api\JobController;
use App\Controller\Api\OpsAdminAccessGuard;
use App\Controller\Api\OpsIngestController;
use App\Controller\Api\OpsReadinessController;
use App\Controller\Api\OpsReadinessReportBuilder;
use App\Derived\DerivedFileRepositoryInterface;
use App\Domain\AppPolicy\FeatureFlagsContractPolicy;
use App\Ingest\Repository\IngestDiagnosticsRepository;
use App\Job\Repository\JobRepository;
use App\Storage\BusinessStorageRegistryInterface;
use App\User\Service\TwoFactorSecretCipher;
use App\User\Service\TwoFactorService;
use App\User\UserTwoFactorState;
use App\User\UserTwoFactorStateRepositoryInterface;
use App\User\Repository\UserRepositoryInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

trait ControllerInstantiationTrait
{
    /**
     * @param class-string $className
     * @param array<string, mixed> $properties
     */
    private function controller(string $className, array $properties): object
    {
        return match ($className) {
            AppController::class => new AppController(
                $this->property($properties, 'translator', $this->translatorStub()),
                $this->property($properties, 'appPolicyEndpointsHandler', $this->defaultAppPolicyEndpointsHandler()),
            ),
            AgentController::class => new AgentController(
                $this->property($properties, 'translator', $this->translatorStub()),
                $this->property($properties, 'registerAgentEndpointHandler', $this->defaultRegisterAgentEndpointHandler()),
                $this->property($properties, 'signedAgentRequestValidator', $this->defaultSignedAgentRequestValidator()),
            ),
            AuthSessionController::class => new AuthSessionController(
                $this->property($properties, 'currentSessionResolver', $this->defaultAuthCurrentSessionResolver()),
                $this->property($properties, 'rateLimitGuard', $this->defaultAuthRateLimitGuard()),
                $this->property($properties, 'sessionResponder', $this->defaultAuthSessionResponder()),
                $this->property($properties, 'errors', $this->defaultAuthErrors()),
                $this->property($properties, 'authSelfServiceEndpointsHandler', $this->defaultAuthSelfServiceEndpointsHandler()),
                $this->property($properties, 'userAccessTokenService', $this->defaultUserAccessTokenService()),
            ),
            AuthTwoFactorController::class => new AuthTwoFactorController(
                $this->property($properties, 'currentSessionResolver', $this->defaultAuthCurrentSessionResolver()),
                $this->property($properties, 'rateLimitGuard', $this->defaultAuthRateLimitGuard()),
                $this->property($properties, 'errors', $this->defaultAuthErrors()),
                $this->property($properties, 'authSelfServiceEndpointsHandler', $this->defaultAuthSelfServiceEndpointsHandler()),
                $this->property($properties, 'twoFactorResponder', $this->defaultAuthTwoFactorResponder()),
            ),
            AuthProfileController::class => new AuthProfileController(
                $this->property($properties, 'authSelfServiceEndpointsHandler', $this->defaultAuthSelfServiceEndpointsHandler()),
                $this->property($properties, 'profileResponder', $this->defaultAuthProfileResponder()),
            ),
            AuthRecoveryController::class => new AuthRecoveryController(
                $this->property($properties, 'requestPasswordResetEndpointHandler', $this->createMock(\App\Application\Auth\RequestPasswordResetEndpointHandler::class)),
                $this->property($properties, 'resetPasswordEndpointHandler', $this->createMock(\App\Application\Auth\ResetPasswordEndpointHandler::class)),
                $this->property($properties, 'requestEmailVerificationEndpointHandler', $this->createMock(\App\Application\Auth\RequestEmailVerificationEndpointHandler::class)),
                $this->property($properties, 'verifyEmailEndpointsHandler', $this->createMock(\App\Application\Auth\VerifyEmailEndpointsHandler::class)),
                $this->property($properties, 'recoveryResponder', $this->defaultAuthRecoveryResponder()),
            ),
            OpsReadinessController::class => new OpsReadinessController(
                $this->property($properties, 'adminAccessGuard', $this->defaultForbiddenAdminGuard()),
                $this->property($properties, 'readinessReportBuilder', $this->defaultOpsReadinessReportBuilder()),
            ),
            OpsIngestController::class => new OpsIngestController(
                $this->property($properties, 'adminAccessGuard', $this->defaultForbiddenAdminGuard()),
                $this->property($properties, 'ingestDiagnostics', new IngestDiagnosticsRepository($this->createStub(Connection::class))),
                $this->property($properties, 'assets', $this->createMock(AssetRepositoryInterface::class)),
                $this->property($properties, 'jobs', new JobRepository($this->createStub(Connection::class), $this->createStub(BusinessStorageRegistryInterface::class))),
                $this->property($properties, 'storageRegistry', $this->createStub(BusinessStorageRegistryInterface::class)),
                $this->property($properties, 'translator', $this->translatorStub()),
            ),
            \App\Controller\DeviceController::class => new \App\Controller\DeviceController(
                $this->property($properties, 'resolveAuthenticatedUserHandler', $this->defaultResolveAuthenticatedUserHandler()),
                $this->property($properties, 'completeDeviceApprovalHandler', $this->defaultCompleteDeviceApprovalHandler()),
                $this->property($properties, 'translator', $this->translatorStub()),
                $this->property($properties, 'twoFactorChallengeRateLimiter', $this->defaultNoLimitRateLimiterFactory()),
            ),
            JobController::class => new JobController(
                $this->property($properties, 'idempotency', new IdempotencyService($this->createMock(Connection::class), $this->translatorStub())),
                $this->property($properties, 'jobEndpointsHandler', $this->defaultJobEndpointsHandler()),
                $this->property($properties, 'logger', $this->createMock(LoggerInterface::class)),
                $this->property($properties, 'translator', $this->translatorStub()),
                $this->property($properties, 'signedAgentRequestValidator', $this->defaultSignedAgentRequestValidator()),
                $this->property($properties, 'agentRuntimeRepository', $this->createMock(AgentRuntimeRepositoryInterface::class)),
            ),
            default => throw new \InvalidArgumentException(sprintf('Unsupported controller test builder for %s', $className)),
        };
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function property(array $properties, string $name, mixed $default): mixed
    {
        return array_key_exists($name, $properties) ? $properties[$name] : $default;
    }

    private function translatorStub(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }

    private function defaultAuthErrors(): AuthApiErrorResponder
    {
        return new AuthApiErrorResponder($this->translatorStub());
    }

    private function defaultAuthSessionResponder(): AuthSessionHttpResponder
    {
        return new AuthSessionHttpResponder($this->defaultAuthErrors());
    }

    private function defaultAuthTwoFactorResponder(): AuthTwoFactorHttpResponder
    {
        return new AuthTwoFactorHttpResponder($this->defaultAuthErrors());
    }

    private function defaultAuthProfileResponder(): AuthProfileHttpResponder
    {
        return new AuthProfileHttpResponder($this->defaultAuthErrors());
    }

    private function defaultAuthRecoveryResponder(): AuthRecoveryHttpResponder
    {
        return new AuthRecoveryHttpResponder($this->defaultAuthErrors());
    }

    private function defaultNoLimitRateLimiterFactory(): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => 'test', 'policy' => 'no_limit'], new InMemoryStorage());
    }

    private function defaultAuthRateLimitGuard(): AuthRateLimitGuard
    {
        return new AuthRateLimitGuard(
            $this->defaultAuthErrors(),
            $this->defaultAuthSessionResponder(),
            $this->defaultNoLimitRateLimiterFactory(),
            $this->defaultNoLimitRateLimiterFactory(),
        );
    }

    private function defaultUserAccessTokenService(): UserAccessTokenService
    {
        return new UserAccessTokenService(
            new UserAuthSessionService($this->createMock(UserAuthSessionRepositoryInterface::class)),
            new UserAccessJwtService('test-secret', 3600),
        );
    }

    private function defaultAuthCurrentSessionResolver(): AuthCurrentSessionResolver
    {
        return new AuthCurrentSessionResolver($this->defaultUserAccessTokenService());
    }

    private function defaultResolveAuthenticatedUserHandler(): ResolveAuthenticatedUserHandler
    {
        return new ResolveAuthenticatedUserHandler(new class implements AuthenticatedUserGateway {
            public function currentUser(): ?array
            {
                return null;
            }
        });
    }

    private function defaultForbiddenAdminGuard(): OpsAdminAccessGuard
    {
        return new OpsAdminAccessGuard(
            new ResolveAdminActorHandler(new class implements AdminActorGateway {
                public function isAdmin(): bool
                {
                    return false;
                }

                public function actorId(): ?string
                {
                    return null;
                }
            }),
            $this->translatorStub(),
        );
    }

    private function defaultOpsReadinessReportBuilder(): OpsReadinessReportBuilder
    {
        return new OpsReadinessReportBuilder(
            $this->createMock(Connection::class),
            $this->createStub(BusinessStorageRegistryInterface::class),
            $this->translatorStub(),
        );
    }

    private function defaultSignedAgentRequestValidator(): SignedAgentRequestValidator
    {
        return new SignedAgentRequestValidator(
            $this->createMock(AgentPublicKeyRepositoryInterface::class),
            $this->createMock(AgentRequestSignatureVerifier::class),
            $this->createMock(AgentSignatureNonceRepositoryInterface::class),
            new SignedAgentMessageCanonicalizer(),
            $this->createMock(AgentRuntimeRepositoryInterface::class),
            $this->translatorStub(),
        );
    }

    private function defaultRegisterAgentEndpointHandler(): RegisterAgentEndpointHandler
    {
        return new RegisterAgentEndpointHandler(
            new class implements RegisterAgentUseCase {
                public function handle(string $actorId, string $agentId, string $agentName, string $clientContractVersion): RegisterAgentResult
                {
                    return new RegisterAgentResult(RegisterAgentResult::STATUS_REGISTERED, [], []);
                }
            },
            new class implements AuthenticatedClientGateway {
                public function currentClient(): ?array
                {
                    return ['client_id' => 'client-1', 'client_kind' => 'interactive'];
                }
            },
            $this->createMock(AgentPublicKeyRepositoryInterface::class),
            $this->createMock(AgentRuntimeRepositoryInterface::class),
        );
    }

    private function defaultCompleteDeviceApprovalHandler(): CompleteDeviceApprovalHandler
    {
        return new CompleteDeviceApprovalHandler(
            new class implements DeviceApprovalSecondFactorGateway {
                public function isEnabled(string $userId): bool
                {
                    return false;
                }

                public function verifyLoginOtp(string $userId, string $otpCode): bool
                {
                    return true;
                }
            },
            new ApproveDeviceFlowHandler(new class implements DeviceFlowGateway {
                public function isMcpDisabledByAppPolicy(): bool
                {
                    return false;
                }

                public function startDeviceFlow(string $clientKind): array
                {
                    return ['device_code' => 'device', 'user_code' => 'USER', 'verification_uri' => '', 'verification_uri_complete' => '', 'expires_in' => 60, 'interval' => 5];
                }

                public function pollDeviceFlow(string $deviceCode): ?array
                {
                    return null;
                }

                public function cancelDeviceFlow(string $deviceCode): ?array
                {
                    return null;
                }

                public function approveDeviceFlow(string $userCode): ?array
                {
                    return ['status' => \App\Domain\AuthClient\DeviceFlowStatus::APPROVED];
                }
            }),
        );
    }

    private function defaultAuthSelfServiceEndpointsHandler(): AuthSelfServiceEndpointsHandler
    {
        $featureGateway = new class implements FeatureGovernanceGateway {
            public function userFeatureEnabled(string $userId): array { return []; }
            public function effectiveFeatureEnabledForUser(string $userId): array { return []; }
            public function effectiveFeatureExplanationsForUser(string $userId): array { return []; }
            public function featureGovernanceRules(): array { return []; }
            public function coreV1GlobalFeatures(): array { return []; }
            public function allowedUserFeatureKeys(): array { return []; }
            public function validateFeaturePayload(array $features, array $allowedKeys): array { return ['unknown_keys' => [], 'non_boolean_keys' => []]; }
            public function setUserFeatureEnabled(string $userId, array $features): void {}
        };

        $twoFactorGateway = new class implements TwoFactorGateway {
            public function setup(string $userId, string $email): array { return ['method' => 'totp', 'issuer' => 'test', 'account_name' => $email, 'secret' => 'secret', 'otpauth_uri' => 'uri']; }
            public function enable(string $userId, string $otpCode): bool { return true; }
            public function disable(string $userId, string $otpCode): bool { return true; }
            public function verifyOtp(string $userId, string $otpCode): bool { return true; }
            public function regenerateRecoveryCodes(string $userId): array { return ['code-1']; }
        };

        $twoFactorStateRepository = new class implements UserTwoFactorStateRepositoryInterface {
            public function findByUserId(string $userId): ?UserTwoFactorState { return null; }
            public function save(UserTwoFactorState $state): void {}
            public function delete(string $userId): void {}
        };

        $cipher = new TwoFactorSecretCipher('v1:'.base64_encode(str_repeat('a', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), 'v1');
        $profileHandler = new AuthSelfServiceProfileEndpointsHandler(
            $this->defaultResolveAuthenticatedUserHandler(),
            new GetAuthMeProfileHandler(
                $this->createMock(UserRepositoryInterface::class),
                new TwoFactorService($twoFactorStateRepository, $cipher),
            ),
            new GetMyFeaturesHandler($featureGateway),
            new PatchMyFeaturesHandler($featureGateway, new GetMyFeaturesHandler($featureGateway)),
        );
        $twoFactorHandler = new AuthSelfServiceTwoFactorEndpointsHandler(
            $this->defaultResolveAuthenticatedUserHandler(),
            new SetupTwoFactorHandler($twoFactorGateway),
            new EnableTwoFactorHandler($twoFactorGateway),
            new DisableTwoFactorHandler($twoFactorGateway),
            new RegenerateTwoFactorRecoveryCodesHandler($twoFactorGateway),
        );

        return new AuthSelfServiceEndpointsHandler($profileHandler, $twoFactorHandler);
    }

    private function defaultAppPolicyEndpointsHandler(): AppPolicyEndpointsHandler
    {
        $gateway = new class implements AppFeatureGovernanceGateway {
            public function appFeatureEnabled(): array { return []; }
            public function appFeatureExplanations(): array { return []; }
            public function featureGovernanceRules(): array { return []; }
            public function coreV1GlobalFeatures(): array { return []; }
            public function allowedAppFeatureKeys(): array { return []; }
            public function validateFeaturePayload(array $features, array $allowedKeys): array { return ['unknown_keys' => [], 'non_boolean_keys' => []]; }
            public function setAppFeatureEnabled(array $features): void {}
        };

        $getFeatures = new GetAppFeaturesHandler($gateway);

        return new AppPolicyEndpointsHandler(
            new GetAppPolicyHandler(new FeatureFlagsContractPolicy(), false, false, false, '1.0.0', []),
            $getFeatures,
            new PatchAppFeaturesHandler($gateway, $getFeatures),
            $this->defaultResolveAuthenticatedUserHandler(),
            new ResolveAdminActorHandler(new class implements AdminActorGateway {
                public function isAdmin(): bool { return false; }
                public function actorId(): ?string { return null; }
            }),
        );
    }

    private function defaultJobEndpointsHandler(): JobEndpointsHandler
    {
        $gateway = $this->createMock(JobGateway::class);
        $resolveConflict = new ResolveJobLockConflictCodeHandler($gateway);

        return new JobEndpointsHandler(
            new ListClaimableJobsHandler($gateway, new JobContractPolicy()),
            new ClaimJobHandler($gateway, new JobContractPolicy()),
            new HeartbeatJobHandler($gateway, $resolveConflict),
            new SubmitJobHandler(
                $gateway,
                new SubmitJobAssetMutator(
                    $this->createMock(AssetRepositoryInterface::class),
                    new AssetStateMachine(),
                    new SubmitJobDerivedPersister($this->createMock(DerivedFileRepositoryInterface::class)),
                ),
                new SubmitJobResultValidator(),
                new CheckSuggestTagsSubmitScopeHandler(false),
                $resolveConflict,
            ),
            new FailJobHandler($gateway, $resolveConflict),
            $this->defaultResolveAuthenticatedUserHandler(),
        );
    }
}
