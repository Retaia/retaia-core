<?php

namespace App\Application\Agent;

final class RegisterAgentResult
{
    public const STATUS_REGISTERED = 'REGISTERED';
    public const STATUS_UNSUPPORTED_CONTRACT_VERSION = 'UNSUPPORTED_CONTRACT_VERSION';

    /**
     * @param array<int, string> $acceptedFeatureFlagsContractVersions
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        private string $status,
        private array $acceptedFeatureFlagsContractVersions,
        private ?array $payload = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array<int, string>
     */
    public function acceptedFeatureFlagsContractVersions(): array
    {
        return $this->acceptedFeatureFlagsContractVersions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        return $this->payload;
    }
}
