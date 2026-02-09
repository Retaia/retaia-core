<?php

namespace App\User\Model;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @param array<int, string> $roles
     */
    public function __construct(
        private string $id,
        private string $email,
        private string $passwordHash,
        private array $roles = ['ROLE_USER'],
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return array<int, string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        if (!\in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }

        return array_values(array_unique($roles));
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function eraseCredentials(): void
    {
    }

    public function withPasswordHash(string $hash): self
    {
        return new self($this->id, $this->email, $hash, $this->roles);
    }
}

