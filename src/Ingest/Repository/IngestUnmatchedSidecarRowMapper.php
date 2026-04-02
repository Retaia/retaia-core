<?php

namespace App\Ingest\Repository;

final class IngestUnmatchedSidecarRowMapper
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{path:string,reason:string,detected_at:string}>
     */
    public static function mapRows(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $path = trim((string) ($row['path'] ?? ''));
            $reason = trim((string) ($row['reason'] ?? ''));
            $detectedAtRaw = trim((string) ($row['detected_at'] ?? ''));
            if ($path === '' || $reason === '' || $detectedAtRaw === '') {
                continue;
            }

            $detectedAt = $detectedAtRaw;
            try {
                $detectedAt = (new \DateTimeImmutable($detectedAtRaw))->format(DATE_ATOM);
            } catch (\Throwable) {
            }

            $items[] = [
                'path' => $path,
                'reason' => $reason,
                'detected_at' => $detectedAt,
            ];
        }

        return $items;
    }
}
