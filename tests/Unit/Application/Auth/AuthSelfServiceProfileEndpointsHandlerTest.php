<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\AuthSelfServiceProfileEndpointsHandler;
use App\Application\Auth\GetAuthMeProfileHandler;
use App\Application\Auth\GetMyFeaturesHandler;
use App\Application\Auth\PatchMyFeaturesHandler;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\Port\FeatureGovernanceGateway;
use App\Entity\User;
use App\User\Repository\UserRepositoryInterface;
use App\User\Service\TwoFactorSecretCipher;
use App\User\Service\TwoFactorService;
use App\User\UserTwoFactorStateRepository;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AuthSelfServiceProfileEndpointsHandlerTest extends TestCase
{
    public function testMeReturnsUnauthorizedWhenNoAuthenticatedUser(): void
    {
        $handler = $this->buildProfileHandler(null);

        self::assertSame('UNAUTHORIZED', $handler->me()->status());
    }

    public function testMeBuildsProfilePayload(): void
    {
        $user = new User('u-1', 'admin@retaia.local', 'hash', ['ROLE_ADMIN']);

        $handler = $this->buildProfileHandler(
            ['id' => 'u-1', 'email' => 'admin@retaia.local', 'roles' => ['ROLE_ADMIN']],
            userRepository: new class($user) implements UserRepositoryInterface {
                public function __construct(private User $user)
                {
                }

                public function findByEmail(string $email): ?User
                {
                    return $this->user;
                }

                public function findById(string $id): ?User
                {
                    return $this->user;
                }

                public function save(User $user): void
                {
                }
            }
        );

        $result = $handler->me();

        self::assertSame('SUCCESS', $result->status());
        self::assertSame('Admin', $result->displayName());
    }

    public function testPatchMyFeaturesReturnsValidationFailedPayloadForNonArrayInput(): void
    {
        $handler = $this->buildProfileHandler(['id' => 'u-2', 'email' => 'user@example.test', 'roles' => ['ROLE_USER']]);

        self::assertSame('VALIDATION_FAILED_PAYLOAD', $handler->patchMyFeatures([])->status());
    }

    public function testPatchMyFeaturesReturnsValidationDetails(): void
    {
        $featureGateway = new class implements FeatureGovernanceGateway {
            public function userFeatureEnabled(string $userId): array
            {
                return [];
            }

            public function effectiveFeatureEnabledForUser(string $userId): array
            {
                return [];
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
                return ['unknown_keys' => ['features.unknown'], 'non_boolean_keys' => []];
            }

            public function setUserFeatureEnabled(string $userId, array $flags): void
            {
            }

            public function userFeatures(string $userId): array
            {
                return [];
            }
        };

        $handler = $this->buildProfileHandler(
            ['id' => 'u-3', 'email' => 'features@retaia.local', 'roles' => ['ROLE_USER']],
            featureGateway: $featureGateway
        );

        $result = $handler->patchMyFeatures(['user_feature_enabled' => ['features.unknown' => true]]);

        self::assertSame('VALIDATION_FAILED', $result->status());
        self::assertSame(['unknown_keys' => ['features.unknown'], 'non_boolean_keys' => []], $result->validationDetails());
    }

    /**
     * @param array{id: string, email: string, roles: array<int, string>}|null $currentUser
     */
    private function buildProfileHandler(
        ?array $currentUser,
        ?UserRepositoryInterface $userRepository = null,
        ?FeatureGovernanceGateway $featureGateway = null,
    ): AuthSelfServiceProfileEndpointsHandler {
        $authenticatedUserGateway = new class($currentUser) implements AuthenticatedUserGateway {
            public function __construct(private ?array $currentUser)
            {
            }

            public function currentUser(): ?array
            {
                return $this->currentUser;
            }
        };

        $userRepository ??= new class implements UserRepositoryInterface {
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
        };

        $featureGateway ??= new class implements FeatureGovernanceGateway {
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
                $userRepository,
                new TwoFactorService(
                    $this->twoFactorRepository(),
                    new TwoFactorSecretCipher('v1:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'v1')
                )
            ),
            new GetMyFeaturesHandler($featureGateway),
            new PatchMyFeaturesHandler($featureGateway, new GetMyFeaturesHandler($featureGateway)),
        );
    }

    private function twoFactorRepository(): UserTwoFactorStateRepository
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE user_two_factor_state (user_id VARCHAR(32) PRIMARY KEY NOT NULL, enabled BOOLEAN NOT NULL, pending_secret_encrypted CLOB DEFAULT NULL, secret_encrypted CLOB DEFAULT NULL, recovery_code_hashes CLOB NOT NULL, legacy_recovery_code_sha256 CLOB NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');

        return new UserTwoFactorStateRepository($connection);
    }
}
