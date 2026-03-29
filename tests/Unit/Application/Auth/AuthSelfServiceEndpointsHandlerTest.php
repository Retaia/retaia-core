<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\AuthMeEndpointResult;
use App\Application\Auth\AuthSelfServiceEndpointsHandler;
use App\Application\Auth\AuthSelfServiceProfileEndpointsHandler;
use App\Application\Auth\AuthSelfServiceTwoFactorEndpointsHandler;
use App\Application\Auth\GetAuthMeProfileHandler;
use App\Application\Auth\GetAuthMeProfileResult;
use App\Application\Auth\GetMyFeaturesHandler;
use App\Application\Auth\PatchMyFeaturesHandler;
use App\Application\Auth\PatchMyFeaturesResult;
use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\Port\FeatureGovernanceGateway;
use App\Application\Auth\Port\TwoFactorGateway;
use App\Application\Auth\RegenerateTwoFactorRecoveryCodesHandler;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\SetupTwoFactorHandler;
use App\Application\Auth\EnableTwoFactorHandler;
use App\Application\Auth\DisableTwoFactorHandler;
use App\Entity\User;
use App\User\Repository\UserRepositoryInterface;
use App\User\Service\TwoFactorSecretCipher;
use App\User\Service\TwoFactorService;
use App\User\UserTwoFactorStateRepository;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AuthSelfServiceEndpointsHandlerTest extends TestCase
{
    public function testFacadeDelegatesMeToProfileHandler(): void
    {
        $handler = new AuthSelfServiceEndpointsHandler(
            $this->profileHandler(['id' => 'u-1', 'email' => 'admin@retaia.local', 'roles' => ['ROLE_ADMIN']]),
            $this->twoFactorHandler(['id' => 'u-1', 'email' => 'admin@retaia.local', 'roles' => ['ROLE_ADMIN']])
        );

        self::assertSame(AuthMeEndpointResult::STATUS_SUCCESS, $handler->me()->status());
    }

    public function testFacadeDelegatesTwoFactorSetup(): void
    {
        $handler = new AuthSelfServiceEndpointsHandler(
            $this->profileHandler(['id' => 'u-1', 'email' => 'user@example.test', 'roles' => ['ROLE_USER']]),
            $this->twoFactorHandler(['id' => 'u-1', 'email' => 'user@example.test', 'roles' => ['ROLE_USER']])
        );

        self::assertSame('READY', $handler->twoFactorSetup()->status());
    }

    public function testFacadeDelegatesPatchMyFeatures(): void
    {
        $handler = new AuthSelfServiceEndpointsHandler(
            $this->profileHandler(['id' => 'u-1', 'email' => 'user@example.test', 'roles' => ['ROLE_USER']]),
            $this->twoFactorHandler(['id' => 'u-1', 'email' => 'user@example.test', 'roles' => ['ROLE_USER']])
        );

        self::assertSame('UPDATED', $handler->patchMyFeatures(['user_feature_enabled' => ['features.ai' => true]])->status());
    }

    /**
     * @param array{id: string, email: string, roles: array<int, string>}|null $currentUser
     */
    private function profileHandler(?array $currentUser): AuthSelfServiceProfileEndpointsHandler
    {
        $authenticatedUserGateway = new class($currentUser) implements AuthenticatedUserGateway {
            public function __construct(private ?array $currentUser)
            {
            }

            public function currentUser(): ?array
            {
                return $this->currentUser;
            }
        };

        $featureGateway = new class implements FeatureGovernanceGateway {
            public function userFeatureEnabled(string $userId): array
            {
                return ['features.ai' => true];
            }

            public function effectiveFeatureEnabledForUser(string $userId): array
            {
                return ['features.ai' => true];
            }

            public function effectiveFeatureExplanationsForUser(string $userId): array
            {
                return [];
            }

            public function featureGovernanceRules(): array
            {
                return [];
            }

            public function coreV1GlobalFeatures(): array
            {
                return [];
            }

            public function allowedUserFeatureKeys(): array
            {
                return ['features.ai'];
            }

            public function validateFeaturePayload(array $payload, array $allowedKeys): array
            {
                return ['unknown_keys' => [], 'non_boolean_keys' => []];
            }

            public function setUserFeatureEnabled(string $userId, array $flags): void
            {
            }

            public function userFeatures(string $userId): array
            {
                return ['features.ai' => true];
            }
        };

        return new AuthSelfServiceProfileEndpointsHandler(
            new ResolveAuthenticatedUserHandler($authenticatedUserGateway),
            new GetAuthMeProfileHandler(
                new class implements UserRepositoryInterface {
                    public function findByEmail(string $email): ?User
                    {
                        return null;
                    }

                    public function findById(string $id): ?User
                    {
                        return null;
                    }

                    public function save(User $user): void
                    {
                    }
                },
                new TwoFactorService(
                    $this->twoFactorRepository(),
                    new TwoFactorSecretCipher('v1:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'v1')
                )
            ),
            new GetMyFeaturesHandler($featureGateway),
            new PatchMyFeaturesHandler($featureGateway, new GetMyFeaturesHandler($featureGateway))
        );
    }

    /**
     * @param array{id: string, email: string, roles: array<int, string>}|null $currentUser
     */
    private function twoFactorHandler(?array $currentUser): AuthSelfServiceTwoFactorEndpointsHandler
    {
        $authenticatedUserGateway = new class($currentUser) implements AuthenticatedUserGateway {
            public function __construct(private ?array $currentUser)
            {
            }

            public function currentUser(): ?array
            {
                return $this->currentUser;
            }
        };

        $gateway = new class implements TwoFactorGateway {
            public function setup(string $userId, string $email): array
            {
                return ['method' => 'TOTP'];
            }

            public function enable(string $userId, string $otpCode): bool
            {
                return true;
            }

            public function disable(string $userId, string $otpCode): bool
            {
                return true;
            }

            public function verifyOtp(string $userId, string $otpCode): bool
            {
                return true;
            }

            public function regenerateRecoveryCodes(string $userId): array
            {
                return ['code-a'];
            }
        };

        return new AuthSelfServiceTwoFactorEndpointsHandler(
            new ResolveAuthenticatedUserHandler($authenticatedUserGateway),
            new SetupTwoFactorHandler($gateway),
            new EnableTwoFactorHandler($gateway),
            new DisableTwoFactorHandler($gateway),
            new RegenerateTwoFactorRecoveryCodesHandler($gateway),
        );
    }

    private function twoFactorRepository(): UserTwoFactorStateRepository
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE user_two_factor_state (user_id VARCHAR(32) PRIMARY KEY NOT NULL, enabled BOOLEAN NOT NULL, pending_secret_encrypted CLOB DEFAULT NULL, secret_encrypted CLOB DEFAULT NULL, recovery_code_hashes CLOB NOT NULL, legacy_recovery_code_sha256 CLOB NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');

        return new UserTwoFactorStateRepository($connection);
    }
}
