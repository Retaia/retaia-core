<?php

namespace App\Auth;

use Doctrine\ORM\EntityManagerInterface;

final class AuthMcpChallengeRepository implements AuthMcpChallengeRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByChallengeId(string $challengeId): ?AuthMcpChallenge
    {
        $challenge = $this->entityManager->find(AuthMcpChallenge::class, $challengeId);
        if ($challenge instanceof AuthMcpChallenge) {
            $this->entityManager->refresh($challenge);
        }

        return $challenge instanceof AuthMcpChallenge ? $challenge : null;
    }

    public function findAll(): array
    {
        /** @var list<AuthMcpChallenge> $challenges */
        $challenges = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(AuthMcpChallenge::class, 'c')
            ->getQuery()
            ->getResult();

        return $challenges;
    }

    public function save(AuthMcpChallenge $challenge): void
    {
        $existing = $this->entityManager->find(AuthMcpChallenge::class, $challenge->challengeId);
        if ($existing instanceof AuthMcpChallenge) {
            $existing->syncFrom($challenge);
            $this->entityManager->flush();

            return;
        }

        $this->entityManager->persist($challenge);
        $this->entityManager->flush();
    }

    public function delete(string $challengeId): void
    {
        $challengeId = trim($challengeId);
        if ($challengeId === '') {
            return;
        }

        $challenge = $this->entityManager->find(AuthMcpChallenge::class, $challengeId);
        if (!$challenge instanceof AuthMcpChallenge) {
            return;
        }

        $this->entityManager->remove($challenge);
        $this->entityManager->flush();
    }
}
