<?php

namespace App\User\Repository;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class PasswordResetTokenRepository implements PasswordResetTokenRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(string $userId, string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        /** @var User $userReference */
        $userReference = $this->entityManager->getReference(User::class, $userId);

        $token = new PasswordResetToken($userReference, $tokenHash, $expiresAt);
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    public function consumeValid(string $tokenHash, \DateTimeImmutable $now): ?string
    {
        $this->entityManager->createQuery(
            'DELETE FROM App\\Entity\\PasswordResetToken t WHERE t.expiresAt < :now'
        )->setParameter('now', $now)->execute();

        $token = $this->entityManager->getRepository(PasswordResetToken::class)->findOneBy([
            'tokenHash' => $tokenHash,
        ]);

        if (!$token instanceof PasswordResetToken) {
            return null;
        }

        $userId = $token->getUserId();
        $this->entityManager->remove($token);
        $this->entityManager->flush();

        return $userId;
    }
}
