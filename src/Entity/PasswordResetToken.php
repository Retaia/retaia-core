<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'password_reset_token')]
#[ORM\Index(name: 'idx_password_reset_token_user_id', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_password_reset_token_hash', columns: ['token_hash'])]
class PasswordResetToken
{
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(name: 'token_hash', type: 'string', length: 64)]
        private string $tokenHash,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $expiresAt,
        #[ORM\Id]
        #[ORM\GeneratedValue]
        #[ORM\Column(type: 'integer')]
        private ?int $id = null,
    ) {
    }

    public function getUserId(): string
    {
        return $this->user->getId();
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
