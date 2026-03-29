<?php

namespace App\Auth;

use Doctrine\ORM\EntityManagerInterface;

final class UserAuthSessionRepository implements UserAuthSessionRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByRefreshToken(string $refreshToken): ?UserAuthSession
    {
        $session = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(UserAuthSession::class, 's')
            ->andWhere('s.refreshToken = :refreshToken')
            ->setParameter('refreshToken', $refreshToken)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $session instanceof UserAuthSession ? $session : null;
    }

    public function findBySessionId(string $sessionId): ?UserAuthSession
    {
        $session = $this->entityManager->find(UserAuthSession::class, $sessionId);

        return $session instanceof UserAuthSession ? $session : null;
    }

    public function findByUserId(string $userId): array
    {
        /** @var list<UserAuthSession> $sessions */
        $sessions = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(UserAuthSession::class, 's')
            ->andWhere('s.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        return $sessions;
    }

    public function save(UserAuthSession $session): void
    {
        $existing = $this->entityManager->find(UserAuthSession::class, $session->sessionId);
        if ($existing instanceof UserAuthSession) {
            $existing->syncFrom($session);
            $this->entityManager->flush();

            return;
        }

        $this->entityManager->persist($session);
        $this->entityManager->flush();
    }

    public function delete(string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        $session = $this->entityManager->find(UserAuthSession::class, $sessionId);
        if (!$session instanceof UserAuthSession) {
            return;
        }

        $this->entityManager->remove($session);
        $this->entityManager->flush();
    }
}
