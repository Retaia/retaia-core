<?php

namespace App\Application\Job\Port;

use App\Job\Job;

interface JobGateway
{
    /**
     * @return array<int, Job>
     */
    public function listClaimable(int $limit): array;

    public function claim(string $jobId, string $actorId, int $ttlSeconds): ?Job;

    public function heartbeat(string $jobId, string $actorId, string $lockToken, int $fencingToken, int $ttlSeconds): ?Job;

    /**
     * @param array<string, mixed> $result
     */
    public function submit(string $jobId, string $actorId, string $lockToken, int $fencingToken, array $result): ?Job;

    public function fail(string $jobId, string $actorId, string $lockToken, int $fencingToken, bool $retryable, string $errorCode, string $message): ?Job;

    public function find(string $jobId): ?Job;
}
