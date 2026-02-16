<?php

namespace App\Application\AuthClient\Input;

final class DeviceCodeInput
{
    private function __construct(
        private string $deviceCode,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(trim((string) ($payload['device_code'] ?? '')));
    }

    public function isValid(): bool
    {
        return $this->deviceCode !== '';
    }

    public function deviceCode(): string
    {
        return $this->deviceCode;
    }
}
