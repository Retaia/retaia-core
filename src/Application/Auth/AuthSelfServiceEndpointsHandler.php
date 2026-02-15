<?php

namespace App\Application\Auth;

final class AuthSelfServiceEndpointsHandler
{
    public function __construct(
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
        private GetAuthMeProfileHandler $getAuthMeProfileHandler,
        private SetupTwoFactorHandler $setupTwoFactorHandler,
        private EnableTwoFactorHandler $enableTwoFactorHandler,
        private DisableTwoFactorHandler $disableTwoFactorHandler,
        private GetMyFeaturesHandler $getMyFeaturesHandler,
        private PatchMyFeaturesHandler $patchMyFeaturesHandler,
    ) {
    }

    public function me(): AuthMeEndpointResult
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new AuthMeEndpointResult(AuthMeEndpointResult::STATUS_UNAUTHORIZED);
        }

        $result = $this->getAuthMeProfileHandler->handle(
            (string) $authenticatedUser->id(),
            (string) $authenticatedUser->email(),
            $authenticatedUser->roles()
        );

        return new AuthMeEndpointResult(
            AuthMeEndpointResult::STATUS_SUCCESS,
            $result->id(),
            $result->email(),
            $result->roles()
        );
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

        return new TwoFactorEnableEndpointResult(TwoFactorEnableEndpointResult::STATUS_ENABLED);
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

    public function getMyFeatures(): GetMyFeaturesEndpointResult
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new GetMyFeaturesEndpointResult(GetMyFeaturesEndpointResult::STATUS_UNAUTHORIZED);
        }

        return new GetMyFeaturesEndpointResult(
            GetMyFeaturesEndpointResult::STATUS_SUCCESS,
            $this->getMyFeaturesHandler->handle((string) $authenticatedUser->id())
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function patchMyFeatures(array $payload): PatchMyFeaturesEndpointResult
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return new PatchMyFeaturesEndpointResult(PatchMyFeaturesEndpointResult::STATUS_UNAUTHORIZED);
        }

        $rawUserFeatures = $payload['user_feature_enabled'] ?? null;
        if (!is_array($rawUserFeatures)) {
            return new PatchMyFeaturesEndpointResult(PatchMyFeaturesEndpointResult::STATUS_VALIDATION_FAILED_PAYLOAD);
        }

        $result = $this->patchMyFeaturesHandler->handle((string) $authenticatedUser->id(), $rawUserFeatures);
        if ($result->status() === PatchMyFeaturesResult::STATUS_FORBIDDEN_SCOPE) {
            return new PatchMyFeaturesEndpointResult(PatchMyFeaturesEndpointResult::STATUS_FORBIDDEN_SCOPE);
        }
        if ($result->status() === PatchMyFeaturesResult::STATUS_VALIDATION_FAILED) {
            return new PatchMyFeaturesEndpointResult(
                PatchMyFeaturesEndpointResult::STATUS_VALIDATION_FAILED,
                $result->validationDetails()
            );
        }

        return new PatchMyFeaturesEndpointResult(
            PatchMyFeaturesEndpointResult::STATUS_UPDATED,
            null,
            $result->features()
        );
    }
}
