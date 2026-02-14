<?php

namespace App\Application\AppPolicy;

final class GetAppPolicyResult
{
    /**
     * @param array<int, string> $acceptedVersions
     * @param array<string, bool> $featureFlags
     */
    public function __construct(
        private bool $supported,
        private array $acceptedVersions,
        private string $latestVersion,
        private string $effectiveVersion,
        private string $compatibilityMode,
        private array $featureFlags,
    ) {
    }

    public function isSupported(): bool
    {
        return $this->supported;
    }

    /**
     * @return array<int, string>
     */
    public function acceptedVersions(): array
    {
        return $this->acceptedVersions;
    }

    public function latestVersion(): string
    {
        return $this->latestVersion;
    }

    public function effectiveVersion(): string
    {
        return $this->effectiveVersion;
    }

    public function compatibilityMode(): string
    {
        return $this->compatibilityMode;
    }

    /**
     * @return array<string, bool>
     */
    public function featureFlags(): array
    {
        return $this->featureFlags;
    }
}
