<?php

namespace App\Infrastructure\Job;

use App\Application\Job\Port\JobGateway as JobGatewayPort;
use App\Job\Job;
use App\Job\Repository\JobRepository;

final class JobGateway implements JobGatewayPort
{
    public function __construct(
        private JobRepository $repository,
    ) {
    }

    public function listClaimable(int $limit): array
    {
        return $this->repository->listClaimable($limit);
    }

    public function claim(string $jobId, string $actorId, int $ttlSeconds): ?Job
    {
        return $this->repository->claim($jobId, $actorId, $ttlSeconds);
    }

    public function heartbeat(string $jobId, string $lockToken, int $ttlSeconds): ?Job
    {
        return $this->repository->heartbeat($jobId, $lockToken, $ttlSeconds);
    }

    public function submit(string $jobId, string $lockToken, array $result): ?Job
    {
        return $this->repository->submit($jobId, $lockToken, $result);
    }

    public function fail(string $jobId, string $lockToken, bool $retryable, string $errorCode, string $message): ?Job
    {
        return $this->repository->fail($jobId, $lockToken, $retryable, $errorCode, $message);
    }

    public function find(string $jobId): ?Job
    {
        return $this->repository->find($jobId);
    }
}
