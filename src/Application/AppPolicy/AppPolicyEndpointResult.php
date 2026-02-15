<?php

namespace App\Application\AppPolicy;

final class AppPolicyEndpointResult
{
    public const STATUS_UNSUPPORTED_CONTRACT_VERSION = 'UNSUPPORTED_CONTRACT_VERSION';
    public const STATUS_SUCCESS = 'SUCCESS';

    /**
     * @param array<int, string>  $acceptedVersions
     * @param array<string, bool> $featureFlags
     */
    public function __construct(
        private string $status,
        private array $acceptedVersions = [],
        private array $featureFlags = [],
        private ?string $latestVersion = null,
        private ?string $effectiveVersion = null,
        private ?string $compatibilityMode = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array<int, string>
     */
    public function acceptedVersions(): array
    {
        return $this->acceptedVersions;
    }

    /**
     * @return array<string, bool>
     */
    public function featureFlags(): array
    {
        return $this->featureFlags;
    }

    public function latestVersion(): ?string
    {
        return $this->latestVersion;
    }

    public function effectiveVersion(): ?string
    {
        return $this->effectiveVersion;
    }

    public function compatibilityMode(): ?string
    {
        return $this->compatibilityMode;
    }
}
