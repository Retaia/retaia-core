<?php

namespace App\Tests\Support;

use App\User\Repository\PasswordResetTokenRepositoryInterface;

final class InMemoryPasswordResetTokenRepository implements PasswordResetTokenRepositoryInterface
{
    /**
     * @var array<int, array{token_hash: string, user_id: string, expires_at: \DateTimeImmutable}>
     */
    private array $rows = [];

    public function save(string $userId, string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        $this->rows[] = [
            'token_hash' => $tokenHash,
            'user_id' => $userId,
            'expires_at' => $expiresAt,
        ];
    }

    public function consumeValid(string $tokenHash, \DateTimeImmutable $now): ?string
    {
        $keptRows = [];
        $userId = null;

        foreach ($this->rows as $row) {
            $isExpired = $row['expires_at'] < $now;
            $isMatch = hash_equals($row['token_hash'], $tokenHash);

            if (!$isMatch || $isExpired) {
                if (!$isExpired) {
                    $keptRows[] = $row;
                }
                continue;
            }

            $userId = $row['user_id'];
        }

        $this->rows = $keptRows;

        return $userId;
    }
}
