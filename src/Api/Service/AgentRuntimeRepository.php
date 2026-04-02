<?php

namespace App\Api\Service;

use Doctrine\DBAL\Connection;

final class AgentRuntimeRepository implements AgentRuntimeRepositoryInterface
{
    private AgentRuntimeProjector $projector;
    private AgentRuntimeWriter $writer;

    public function __construct(
        private Connection $connection,
    ) {
        $rowMapper = new AgentRuntimeRowMapper();
        $this->projector = new AgentRuntimeProjector($this->connection, $rowMapper);
        $this->writer = new AgentRuntimeWriter($this->connection, $this->projector, $rowMapper);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function saveRegistration(array $entry): void
    {
        $this->writer->saveRegistration($entry);
    }

    public function touchSeen(string $agentId): void
    {
        $this->writer->touchSeen($agentId);
    }

    public function touchHeartbeat(string $agentId): void
    {
        $this->writer->touchHeartbeat($agentId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->projector->findAll();
    }
}
