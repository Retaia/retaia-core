<?php

namespace App\Application\Auth;

final class AuthSelfServiceTwoFactorEndpointsHandler
{
    public function __construct(
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
        private SetupTwoFactorHandler $setupTwoFactorHandler,
        private EnableTwoFactorHandler $enableTwoFactorHandler,
        private DisableTwoFactorHandler $disableTwoFactorHandler,
        private RegenerateTwoFactorRecoveryCodesHandler $regenerateTwoFactorRecoveryCodesHandler,
    ) {
    }

    public function twoFactorSetup(): TwoFactorSetupEndpointResult
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new TwoFactorSetupEndpointResult(TwoFactorSetupEndpointResult::STATUS_UNAUTHORIZED);
        }

        $result = $this->setupTwoFactorHandler->handle((string) $authenticatedUser->id(), (string) $authenticatedUser->email());
        if ($result->status() === SetupTwoFactorResult::STATUS_ALREADY_ENABLED) {
            return new TwoFactorSetupEndpointResult(TwoFactorSetupEndpointResult::STATUS_ALREADY_ENABLED);
        }

        return new TwoFactorSetupEndpointResult(TwoFactorSetupEndpointResult::STATUS_READY, $result->setup());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function twoFactorEnable(array $payload): TwoFactorEnableEndpointResult
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new TwoFactorEnableEndpointResult(TwoFactorEnableEndpointResult::STATUS_UNAUTHORIZED);
        }

        $otpCode = trim((string) ($payload['otp_code'] ?? ''));
        if ($otpCode === '') {
            return new TwoFactorEnableEndpointResult(TwoFactorEnableEndpointResult::STATUS_VALIDATION_FAILED);
        }

        $result = $this->enableTwoFactorHandler->handle((string) $authenticatedUser->id(), $otpCode);
        if ($result->status() === EnableTwoFactorResult::STATUS_ALREADY_ENABLED) {
            return new TwoFactorEnableEndpointResult(TwoFactorEnableEndpointResult::STATUS_ALREADY_ENABLED);
        }
        if ($result->status() === EnableTwoFactorResult::STATUS_SETUP_REQUIRED) {
            return new TwoFactorEnableEndpointResult(TwoFactorEnableEndpointResult::STATUS_SETUP_REQUIRED);
        }
        if ($result->status() === EnableTwoFactorResult::STATUS_INVALID_CODE) {
            return new TwoFactorEnableEndpointResult(TwoFactorEnableEndpointResult::STATUS_INVALID_CODE);
        }

        return new TwoFactorEnableEndpointResult(
            TwoFactorEnableEndpointResult::STATUS_ENABLED,
            $result->recoveryCodes()
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function twoFactorDisable(array $payload): TwoFactorDisableEndpointResult
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new TwoFactorDisableEndpointResult(TwoFactorDisableEndpointResult::STATUS_UNAUTHORIZED);
        }

        $otpCode = trim((string) ($payload['otp_code'] ?? ''));
        if ($otpCode === '') {
            return new TwoFactorDisableEndpointResult(TwoFactorDisableEndpointResult::STATUS_VALIDATION_FAILED);
        }

        $result = $this->disableTwoFactorHandler->handle((string) $authenticatedUser->id(), $otpCode);
        if ($result->status() === DisableTwoFactorResult::STATUS_NOT_ENABLED) {
            return new TwoFactorDisableEndpointResult(TwoFactorDisableEndpointResult::STATUS_NOT_ENABLED);
        }
        if ($result->status() === DisableTwoFactorResult::STATUS_INVALID_CODE) {
            return new TwoFactorDisableEndpointResult(TwoFactorDisableEndpointResult::STATUS_INVALID_CODE);
        }

        return new TwoFactorDisableEndpointResult(TwoFactorDisableEndpointResult::STATUS_DISABLED);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function regenerateTwoFactorRecoveryCodes(array $payload): TwoFactorRecoveryCodesEndpointResult
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new TwoFactorRecoveryCodesEndpointResult(TwoFactorRecoveryCodesEndpointResult::STATUS_UNAUTHORIZED);
        }

        $otpCode = trim((string) ($payload['otp_code'] ?? ''));
        if ($otpCode === '') {
            return new TwoFactorRecoveryCodesEndpointResult(TwoFactorRecoveryCodesEndpointResult::STATUS_VALIDATION_FAILED);
        }

        $result = $this->regenerateTwoFactorRecoveryCodesHandler->handle((string) $authenticatedUser->id(), $otpCode);
        if ($result->status() === RegenerateTwoFactorRecoveryCodesResult::STATUS_NOT_ENABLED) {
            return new TwoFactorRecoveryCodesEndpointResult(TwoFactorRecoveryCodesEndpointResult::STATUS_NOT_ENABLED);
        }
        if ($result->status() === RegenerateTwoFactorRecoveryCodesResult::STATUS_INVALID_CODE) {
            return new TwoFactorRecoveryCodesEndpointResult(TwoFactorRecoveryCodesEndpointResult::STATUS_INVALID_CODE);
        }

        return new TwoFactorRecoveryCodesEndpointResult(
            TwoFactorRecoveryCodesEndpointResult::STATUS_REGENERATED,
            $result->codes()
        );
    }
}
