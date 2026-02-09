<?php

namespace App\User\Service;

use App\User\Repository\UserRepositoryInterface;
use App\User\Repository\PasswordResetTokenRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private PasswordResetTokenRepositoryInterface $tokens,
        private UserPasswordHasherInterface $passwordHasher,
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
        $this->tokens->save(
            $user->getId(),
            hash('sha256', $token),
            new \DateTimeImmutable('+1 hour'),
        );

        if ($this->environment !== 'prod') {
            return $token;
        }

        return null;
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        $userId = $this->tokens->consumeValid(hash('sha256', $token), new \DateTimeImmutable());
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
}
