<?php

namespace App\Api\Service;

interface AgentRuntimeRepositoryInterface
{
    /**
     * @param array<string, mixed> $entry
     */
    public function saveRegistration(array $entry): void;

    public function touchSeen(string $agentId): void;

    public function touchHeartbeat(string $agentId): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array;
}
