<?php

namespace App\User\Service;

use App\User\Repository\UserRepositoryInterface;
use App\User\Repository\PasswordResetTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private PasswordResetTokenRepositoryInterface $tokens,
        private UserPasswordHasherInterface $passwordHasher,
        private LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%app.password_reset_ttl_seconds%')]
        private int $tokenTtlSeconds,
    ) {
    }

    /**
     * Returns null when the user does not exist, or a token only in non-prod env.
     */
    public function requestReset(string $email): ?string
    {
        $emailHash = hash('sha256', mb_strtolower(trim($email)));
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            $this->logger->info('auth.password_reset.request.ignored', [
                'email_hash' => $emailHash,
                'reason' => 'user_not_found',
            ]);

            return null;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->tokens->save(
            $user->getId(),
            hash('sha256', $token),
            new \DateTimeImmutable(sprintf('+%d seconds', $this->tokenTtlSeconds)),
        );

        $this->logger->info('auth.password_reset.request.accepted', [
            'user_id' => $user->getId(),
            'email_hash' => $emailHash,
            'ttl_seconds' => $this->tokenTtlSeconds,
            'token_exposed' => $this->environment !== 'prod',
        ]);

        if ($this->environment !== 'prod') {
            return $token;
        }

        return null;
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        $userId = $this->tokens->consumeValid(hash('sha256', $token), new \DateTimeImmutable());
        if ($userId === null || $userId === '') {
            $this->logger->info('auth.password_reset.failed', [
                'reason' => 'invalid_or_expired_token',
            ]);

            return false;
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            $this->logger->warning('auth.password_reset.failed', [
                'reason' => 'user_not_found',
                'user_id' => $userId,
            ]);

            return false;
        }

        $this->users->save($user->withPasswordHash($this->passwordHasher->hashPassword($user, $newPassword)));
        $this->logger->info('auth.password_reset.completed', [
            'user_id' => $userId,
        ]);

        return true;
    }
}
