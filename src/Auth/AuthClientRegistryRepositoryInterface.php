<?php

namespace App\Auth;

interface AuthClientRegistryRepositoryInterface
{
    public function findByClientId(string $clientId): ?AuthClientRegistryEntry;

    /**
     * @return list<AuthClientRegistryEntry>
     */
    public function findAll(): array;

    public function save(AuthClientRegistryEntry $entry): void;
}
