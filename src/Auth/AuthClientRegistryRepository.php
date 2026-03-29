<?php

namespace App\Auth;

use App\Domain\AuthClient\ClientKind;
use Doctrine\ORM\EntityManagerInterface;

final class AuthClientRegistryRepository implements AuthClientRegistryRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByClientId(string $clientId): ?AuthClientRegistryEntry
    {
        $this->ensureDefaults();
        $entry = $this->entityManager->find(AuthClientRegistryEntry::class, $clientId);
        if ($entry instanceof AuthClientRegistryEntry) {
            $this->entityManager->refresh($entry);
        }

        return $entry instanceof AuthClientRegistryEntry ? $entry : null;
    }

    public function findAll(): array
    {
        $this->ensureDefaults();

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

    private function ensureDefaults(): void
    {
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.clientId)')
            ->from(AuthClientRegistryEntry::class, 'c')
            ->getQuery()
            ->getSingleScalarResult();
        if ($count > 0) {
            return;
        }

        $this->entityManager->persist(new AuthClientRegistryEntry(
            'agent-default',
            ClientKind::AGENT,
            'agent-secret',
            null,
            null,
            null,
            null,
            null,
        ));
        $this->entityManager->persist(new AuthClientRegistryEntry(
            'mcp-default',
            ClientKind::MCP,
            'mcp-secret',
            null,
            null,
            null,
            null,
            null,
        ));
        $this->entityManager->flush();
    }
}
