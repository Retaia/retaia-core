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

    public function heartbeat(string $jobId, string $actorId, string $lockToken, int $fencingToken, int $ttlSeconds): ?Job
    {
        return $this->repository->heartbeat($jobId, $actorId, $lockToken, $fencingToken, $ttlSeconds);
    }

    public function submit(string $jobId, string $actorId, string $lockToken, int $fencingToken, array $result): ?Job
    {
        return $this->repository->submit($jobId, $actorId, $lockToken, $fencingToken, $result);
    }

    public function fail(string $jobId, string $actorId, string $lockToken, int $fencingToken, bool $retryable, string $errorCode, string $message): ?Job
    {
        return $this->repository->fail($jobId, $actorId, $lockToken, $fencingToken, $retryable, $errorCode, $message);
    }

    public function find(string $jobId): ?Job
    {
        return $this->repository->find($jobId);
    }
}
