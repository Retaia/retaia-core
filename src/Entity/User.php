<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @param array<int, string> $roles
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 180)]
        private string $email,
        #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
        private string $passwordHash,
        #[ORM\Column(type: 'json')]
        private array $roles = ['ROLE_USER'],
        #[ORM\Column(type: 'boolean')]
        private bool $emailVerified = false,
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

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function withEmailVerified(bool $verified): self
    {
        $this->emailVerified = $verified;

        return $this;
    }

    public function withPasswordHash(string $hash): self
    {
        $this->passwordHash = $hash;

        return $this;
    }
}
