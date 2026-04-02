<?php

namespace App\Auth;

use Doctrine\ORM\EntityManagerInterface;

final class AuthClientRegistryRepository implements AuthClientRegistryRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByClientId(string $clientId): ?AuthClientRegistryEntry
    {
        $entry = $this->entityManager->find(AuthClientRegistryEntry::class, $clientId);
        if ($entry instanceof AuthClientRegistryEntry) {
            $this->entityManager->refresh($entry);
        }

        return $entry instanceof AuthClientRegistryEntry ? $entry : null;
    }

    public function findAll(): array
    {
        /** @var list<AuthClientRegistryEntry> $entries */
        $entries = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(AuthClientRegistryEntry::class, 'c')
            ->getQuery()
            ->getResult();

        return $entries;
    }

    public function save(AuthClientRegistryEntry $entry): void
    {
        $existing = $this->entityManager->find(AuthClientRegistryEntry::class, $entry->clientId);
        if ($existing instanceof AuthClientRegistryEntry) {
            $existing->syncFrom($entry);
            $this->entityManager->flush();

            return;
        }

        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }
}
