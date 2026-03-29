<?php

namespace App\Application\Auth;

final class AuthSelfServiceEndpointsHandler
{
    public function __construct(
        private AuthSelfServiceProfileEndpointsHandler $profileEndpointsHandler,
        private AuthSelfServiceTwoFactorEndpointsHandler $twoFactorEndpointsHandler,
    ) {
    }

    public function me(): AuthMeEndpointResult
    {
        return $this->profileEndpointsHandler->me();
    }

    public function twoFactorSetup(): TwoFactorSetupEndpointResult
    {
        return $this->twoFactorEndpointsHandler->twoFactorSetup();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function twoFactorEnable(array $payload): TwoFactorEnableEndpointResult
    {
        return $this->twoFactorEndpointsHandler->twoFactorEnable($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function twoFactorDisable(array $payload): TwoFactorDisableEndpointResult
    {
        return $this->twoFactorEndpointsHandler->twoFactorDisable($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function regenerateTwoFactorRecoveryCodes(array $payload): TwoFactorRecoveryCodesEndpointResult
    {
        return $this->twoFactorEndpointsHandler->regenerateTwoFactorRecoveryCodes($payload);
    }

    public function getMyFeatures(): GetMyFeaturesEndpointResult
    {
        return $this->profileEndpointsHandler->getMyFeatures();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function patchMyFeatures(array $payload): PatchMyFeaturesEndpointResult
    {
        return $this->profileEndpointsHandler->patchMyFeatures($payload);
    }
}
