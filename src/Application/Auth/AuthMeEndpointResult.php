<?php

namespace App\Application\Auth;

final class AuthMeEndpointResult
{
    public const STATUS_UNAUTHORIZED = 'UNAUTHORIZED';
    public const STATUS_SUCCESS = 'SUCCESS';

    /**
     * @param array<int, string> $roles
     */
    public function __construct(
        private string $status,
        private ?string $id = null,
        private ?string $email = null,
        private array $roles = [],
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function email(): ?string
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
}
