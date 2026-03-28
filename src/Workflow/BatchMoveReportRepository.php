<?php

namespace App\Workflow;

use Doctrine\DBAL\Connection;

final class BatchMoveReportRepository implements BatchMoveReportRepositoryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function store(string $batchId, array $payload): void
    {
        $this->connection->insert('batch_move_report', [
            'batch_id' => $batchId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $batchId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT payload FROM batch_move_report WHERE batch_id = :batchId',
            ['batchId' => $batchId]
        );

        if (!is_array($row) || !is_string($row['payload'] ?? null)) {
            return null;
        }

        $decoded = json_decode((string) $row['payload'], true);

        return is_array($decoded) ? $decoded : null;
    }
}
