<?php

namespace App\Tests\Unit\Application\Auth;

use App\Application\Auth\AuthSelfServiceTwoFactorEndpointsHandler;
use App\Application\Auth\DisableTwoFactorHandler;
use App\Application\Auth\EnableTwoFactorHandler;
use App\Application\Auth\RegenerateTwoFactorRecoveryCodesHandler;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\SetupTwoFactorHandler;
use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\Port\TwoFactorGateway;
use PHPUnit\Framework\TestCase;

final class AuthSelfServiceTwoFactorEndpointsHandlerTest extends TestCase
{
    public function testTwoFactorSetupReturnsUnauthorizedWhenNoAuthenticatedUser(): void
    {
        $handler = $this->buildHandler(null);

        self::assertSame('UNAUTHORIZED', $handler->twoFactorSetup()->status());
    }

    public function testTwoFactorEnableReturnsValidationFailedWhenOtpMissing(): void
    {
        $handler = $this->buildHandler(['id' => 'u-1', 'email' => 'user@example.test', 'roles' => ['ROLE_USER']]);

        self::assertSame('VALIDATION_FAILED', $handler->twoFactorEnable([])->status());
    }

    public function testTwoFactorDisableReturnsInvalidCodeWhenGatewayRejects(): void
    {
        $gateway = new class implements TwoFactorGateway {
            public function setup(string $userId, string $email): array
            {
                return [];
            }

            public function enable(string $userId, string $otpCode): bool
            {
                return true;
            }

            public function disable(string $userId, string $otpCode): bool
            {
                return false;
            }

            public function verifyOtp(string $userId, string $otpCode): bool
            {
                return true;
            }

            public function regenerateRecoveryCodes(string $userId): array
            {
                return [];
            }
        };

        $handler = $this->buildHandler(['id' => 'u-1', 'email' => 'user@example.test', 'roles' => ['ROLE_USER']], $gateway);

        self::assertSame('INVALID_CODE', $handler->twoFactorDisable(['otp_code' => '000000'])->status());
    }

    public function testRegenerateRecoveryCodesReturnsCodes(): void
    {
        $gateway = new class implements TwoFactorGateway {
            public function setup(string $userId, string $email): array
            {
                return [];
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
                return ['code-a', 'code-b'];
            }
        };

        $handler = $this->buildHandler(['id' => 'u-1', 'email' => 'user@example.test', 'roles' => ['ROLE_USER']], $gateway);
        $result = $handler->regenerateTwoFactorRecoveryCodes(['otp_code' => '123456']);

        self::assertSame('REGENERATED', $result->status());
        self::assertSame(['code-a', 'code-b'], $result->recoveryCodes());
    }

    public function testRegenerateRecoveryCodesReturnsValidationFailedWhenOtpMissing(): void
    {
        $handler = $this->buildHandler(['id' => 'u-1', 'email' => 'user@example.test', 'roles' => ['ROLE_USER']]);

        self::assertSame('VALIDATION_FAILED', $handler->regenerateTwoFactorRecoveryCodes([])->status());
    }

    /**
     * @param array{id: string, email: string, roles: array<int, string>}|null $currentUser
     */
    private function buildHandler(?array $currentUser, ?TwoFactorGateway $gateway = null): AuthSelfServiceTwoFactorEndpointsHandler
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

        $gateway ??= new class implements TwoFactorGateway {
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
}
