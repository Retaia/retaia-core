<?php

namespace App\Application\Auth;

final class GetAuthMeProfileResult
{
    /**
     * @param array<int, string> $roles
     */
    public function __construct(
        private string $id,
        private string $email,
        private array $roles,
        private ?string $displayName = null,
        private bool $emailVerified = false,
        private bool $mfaEnabled = false,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    /**
     * @return array<int, string>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    public function displayName(): ?string
    {
        return $this->displayName;
    }

    public function emailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function mfaEnabled(): bool
    {
        return $this->mfaEnabled;
    }
}
