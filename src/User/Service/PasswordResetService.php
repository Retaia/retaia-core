<?php

namespace App\User\Service;

use App\User\Repository\UserRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%app.password_reset_storage_path%')]
        private string $tokenStoragePath,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    /**
     * Returns null when the user does not exist, or a token only in non-prod env.
     */
    public function requestReset(string $email): ?string
    {
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            return null;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $rows = $this->readRows();
        $rows[] = [
            'token_hash' => hash('sha256', $token),
            'user_id' => $user->getId(),
            'expires_at' => time() + 3600,
        ];
        $this->writeRows($rows);

        if ($this->environment !== 'prod') {
            return $token;
        }

        return null;
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        $tokenHash = hash('sha256', $token);
        $rows = $this->readRows();
        $keptRows = [];
        $userId = null;

        foreach ($rows as $row) {
            $isExpired = (int) ($row['expires_at'] ?? 0) < time();
            $isMatch = hash_equals((string) ($row['token_hash'] ?? ''), $tokenHash);

            if (!$isMatch || $isExpired) {
                if (!$isExpired) {
                    $keptRows[] = $row;
                }
                continue;
            }

            $userId = (string) ($row['user_id'] ?? '');
        }

        $this->writeRows($keptRows);
        if ($userId === null || $userId === '') {
            return false;
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            return false;
        }

        $this->users->save($user->withPasswordHash($this->passwordHasher->hashPassword($user, $newPassword)));

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readRows(): array
    {
        if (!is_file($this->tokenStoragePath)) {
            return [];
        }

        $json = file_get_contents($this->tokenStoragePath);
        if ($json === false || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeRows(array $rows): void
    {
        $directory = dirname($this->tokenStoragePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($this->tokenStoragePath, (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
