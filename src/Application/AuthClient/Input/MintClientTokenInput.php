<?php

namespace App\Application\AuthClient\Input;

final class MintClientTokenInput
{
    private function __construct(
        private string $clientId,
        private string $clientKind,
        private string $secretKey,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            trim((string) ($payload['client_id'] ?? '')),
            trim((string) ($payload['client_kind'] ?? '')),
            trim((string) ($payload['secret_key'] ?? '')),
        );
    }

    public function isValid(): bool
    {
        return $this->clientId !== '' && $this->clientKind !== '' && $this->secretKey !== '';
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function clientKind(): string
    {
        return $this->clientKind;
    }

    public function secretKey(): string
    {
        return $this->secretKey;
    }
}
