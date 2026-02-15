<?php

namespace App\Application\Derived;

final class InitDerivedUploadResult
{
    public const STATUS_NOT_FOUND = 'NOT_FOUND';
    public const STATUS_INITIALIZED = 'INITIALIZED';

    /**
     * @param array<string, mixed>|null $session
     */
    public function __construct(
        private string $status,
        private ?array $session = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function session(): ?array
    {
        return $this->session;
    }
}
