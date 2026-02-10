<?php

namespace App\Observability\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class MetricEventRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function record(string $metricKey): void
    {
        try {
            $this->connection->insert('ops_metric_event', [
                'metric_key' => $metricKey,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Telemetry must never break the API path.
        }
    }

    public function countSince(string $metricKey, \DateTimeImmutable $since): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ops_metric_event
             WHERE metric_key = :metricKey
               AND created_at >= :since',
            [
                'metricKey' => $metricKey,
                'since' => $since->format('Y-m-d H:i:s'),
            ],
            [
                'metricKey' => ParameterType::STRING,
                'since' => ParameterType::STRING,
            ]
        );
    }
}

