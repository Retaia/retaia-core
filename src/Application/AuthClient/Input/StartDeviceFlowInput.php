<?php

namespace App\Application\AuthClient\Input;

final class StartDeviceFlowInput
{
    private function __construct(
        private string $clientKind,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(trim((string) ($payload['client_kind'] ?? '')));
    }

    public function isValid(): bool
    {
        return $this->clientKind !== '';
    }

    public function clientKind(): string
    {
        return $this->clientKind;
    }
}
