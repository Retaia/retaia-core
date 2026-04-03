<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\AuthClientRegistryEntry;
use App\Auth\AuthClientRegistryRepositoryInterface;

final class InMemoryAuthClientRegistryRepository implements AuthClientRegistryRepositoryInterface
{
    /** @var array<string, AuthClientRegistryEntry> */
    private array $entries = [];

    public function __construct(AuthClientRegistryEntry ...$entries)
    {
        foreach ($entries as $entry) {
            $this->entries[$entry->clientId] = $entry;
        }
    }

    public function findByClientId(string $clientId): ?AuthClientRegistryEntry
    {
        return $this->entries[$clientId] ?? null;
    }

    public function findAll(): array
    {
        return array_values($this->entries);
    }

    public function save(AuthClientRegistryEntry $entry): void
    {
        $this->entries[$entry->clientId] = $entry;
    }
}
