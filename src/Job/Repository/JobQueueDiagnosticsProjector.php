<?php

namespace App\Job\Repository;

use App\Job\JobStatus;

final class JobQueueDiagnosticsProjector
{
    /**
     * @param array<int, array<string, mixed>> $summaryRows
     * @param array<int, array<string, mixed>> $byTypeRows
     * @param array<int, array<string, mixed>> $oldestPendingRows
     * @return array{
     *     summary:array{pending_total:int,claimed_total:int,failed_total:int},
     *     by_type:array<int, array{job_type:string,pending:int,claimed:int,failed:int,oldest_pending_age_seconds:?int}>
     * }
     */
    public function project(array $summaryRows, array $byTypeRows, array $oldestPendingRows, ?\DateTimeImmutable $now = null): array
    {
        $summary = [
            'pending_total' => 0,
            'claimed_total' => 0,
            'failed_total' => 0,
        ];
        foreach ($summaryRows as $row) {
            $status = trim((string) ($row['status'] ?? ''));
            $total = max(0, (int) ($row['total'] ?? 0));
            if ($status === JobStatus::PENDING->value) {
                $summary['pending_total'] = $total;
            } elseif ($status === JobStatus::CLAIMED->value) {
                $summary['claimed_total'] = $total;
            } elseif ($status === JobStatus::FAILED->value) {
                $summary['failed_total'] = $total;
            }
        }

        /** @var array<string, array{job_type:string,pending:int,claimed:int,failed:int,oldest_pending_age_seconds:?int}> $byType */
        $byType = [];
        foreach ($byTypeRows as $row) {
            $jobType = trim((string) ($row['job_type'] ?? ''));
            $status = trim((string) ($row['status'] ?? ''));
            $total = max(0, (int) ($row['total'] ?? 0));
            if ($jobType === '') {
                continue;
            }

            if (!isset($byType[$jobType])) {
                $byType[$jobType] = [
                    'job_type' => $jobType,
                    'pending' => 0,
                    'claimed' => 0,
                    'failed' => 0,
                    'oldest_pending_age_seconds' => null,
                ];
            }

            if ($status === JobStatus::PENDING->value) {
                $byType[$jobType]['pending'] = $total;
            } elseif ($status === JobStatus::CLAIMED->value) {
                $byType[$jobType]['claimed'] = $total;
            } elseif ($status === JobStatus::FAILED->value) {
                $byType[$jobType]['failed'] = $total;
            }
        }

        $reference = $now ?? new \DateTimeImmutable();
        foreach ($oldestPendingRows as $row) {
            $jobType = trim((string) ($row['job_type'] ?? ''));
            $oldestRaw = trim((string) ($row['oldest_pending_at'] ?? ''));
            if ($jobType === '' || !isset($byType[$jobType]) || $oldestRaw === '') {
                continue;
            }

            try {
                $oldest = new \DateTimeImmutable($oldestRaw);
                $age = max(0, $reference->getTimestamp() - $oldest->getTimestamp());
                $byType[$jobType]['oldest_pending_age_seconds'] = $age;
            } catch (\Throwable) {
                $byType[$jobType]['oldest_pending_age_seconds'] = null;
            }
        }

        return [
            'summary' => $summary,
            'by_type' => array_values($byType),
        ];
    }
}
