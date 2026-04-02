<?php

namespace App\Ingest\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\ParameterType;

final class IngestUnmatchedSidecarListingProjector
{
    private const ALLOWED_UNMATCHED_REASONS = [
        'missing_parent',
        'ambiguous_parent',
        'disabled_by_policy',
        'storage_mismatch',
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array{
     *     items:array<int, array{path:string,reason:string,detected_at:string}>,
     *     total:int
     * }
     */
    public function unmatchedSnapshot(?string $reason, ?\DateTimeImmutable $since, int $limit = 50): array
    {
        $normalizedReason = $this->normalizeReason($reason);
        $normalizedSince = $since?->setTimezone(new \DateTimeZone('UTC'));
        $normalizedLimit = max(1, min(200, $limit));

        return [
            'items' => $this->listUnmatchedSidecars($normalizedReason, $normalizedSince, $normalizedLimit),
            'total' => $this->countUnmatchedSidecars($normalizedReason, $normalizedSince),
        ];
    }

    private function countUnmatchedSidecars(?string $reason, ?\DateTimeImmutable $since): int
    {
        $whereParts = [];
        $params = [];

        if ($reason !== null) {
            $whereParts[] = 'reason = :reason';
            $params['reason'] = $reason;
        }

        if ($since instanceof \DateTimeImmutable) {
            $whereParts[] = 'detected_at >= :since';
            $params['since'] = $since->format('Y-m-d H:i:s');
        }

        $where = $whereParts === [] ? '' : ' WHERE '.implode(' AND ', $whereParts);

        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ingest_unmatched_sidecar'.$where,
                $params
            );
        } catch (TableNotFoundException) {
            return 0;
        }
    }

    /**
     * @return array<int, array{path:string,reason:string,detected_at:string}>
     */
    private function listUnmatchedSidecars(?string $reason, ?\DateTimeImmutable $since, int $limit): array
    {
        $whereParts = [];
        $params = ['limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];

        if ($reason !== null) {
            $whereParts[] = 'reason = :reason';
            $params['reason'] = $reason;
        }

        if ($since instanceof \DateTimeImmutable) {
            $whereParts[] = 'detected_at >= :since';
            $params['since'] = $since->format('Y-m-d H:i:s');
        }

        $where = $whereParts === [] ? '' : ' WHERE '.implode(' AND ', $whereParts);

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT path, reason, detected_at
                 FROM ingest_unmatched_sidecar'
                .$where.
                ' ORDER BY detected_at DESC
                 LIMIT :limit',
                $params,
                $types
            );
        } catch (TableNotFoundException) {
            return [];
        }

        return IngestUnmatchedSidecarRowMapper::mapRows($rows);
    }

    private function normalizeReason(?string $reason): ?string
    {
        $normalized = trim((string) $reason);
        if ($normalized === '' || !in_array($normalized, self::ALLOWED_UNMATCHED_REASONS, true)) {
            return null;
        }

        return $normalized;
    }
}
