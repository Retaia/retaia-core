<?php

namespace App\Api\Service;

use Doctrine\DBAL\Connection;

final class AgentJobProjectionQueryRunner
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param array<int, string> $agentIds
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchRowsForIds(string $sqlTemplate, string $column, array $agentIds, array $params): array
    {
        $placeholders = [];
        foreach ($agentIds as $index => $agentId) {
            $key = sprintf('%s_%d', $column, $index);
            $placeholders[] = ':'.$key;
            $params[$key] = $agentId;
        }

        return $this->connection->fetchAllAssociative(sprintf($sqlTemplate, implode(', ', $placeholders)), $params);
    }
}
