<?php

namespace App\User\Repository;

interface PasswordResetTokenRepositoryInterface
{
    public function save(string $userId, string $tokenHash, \DateTimeImmutable $expiresAt): void;

    public function consumeValid(string $tokenHash, \DateTimeImmutable $now): ?string;
}
