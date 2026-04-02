<?php

namespace App\Application\Job;

use App\Application\Auth\ResolveAuthenticatedUserHandler;

final class JobEndpointsHandler
{
    private JobListEndpointHandler $listEndpointHandler;
    private JobClaimEndpointHandler $claimEndpointHandler;
    private JobHeartbeatEndpointHandler $heartbeatEndpointHandler;
    private JobSubmitEndpointHandler $submitEndpointHandler;
    private JobFailEndpointHandler $failEndpointHandler;

    public function __construct(
        private ListClaimableJobsHandler $listClaimableJobsHandler,
        private ClaimJobHandler $claimJobHandler,
        private HeartbeatJobHandler $heartbeatJobHandler,
        private SubmitJobHandler $submitJobHandler,
        private FailJobHandler $failJobHandler,
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
    ) {
        $actorContextResolver = new JobEndpointActorContextResolver($this->resolveAuthenticatedUserHandler);
        $fencingTokenParser = new JobEndpointFencingTokenParser();

        $this->listEndpointHandler = new JobListEndpointHandler($this->listClaimableJobsHandler, $actorContextResolver);
        $this->claimEndpointHandler = new JobClaimEndpointHandler($this->claimJobHandler, $actorContextResolver);
        $this->heartbeatEndpointHandler = new JobHeartbeatEndpointHandler($this->heartbeatJobHandler, $actorContextResolver, $fencingTokenParser);
        $this->submitEndpointHandler = new JobSubmitEndpointHandler($this->submitJobHandler, $actorContextResolver, $fencingTokenParser);
        $this->failEndpointHandler = new JobFailEndpointHandler($this->failJobHandler, $actorContextResolver, $fencingTokenParser);
    }

    public function list(int $limit): JobEndpointResult
    {
        return $this->listEndpointHandler->handle($limit);
    }

    public function claim(string $jobId): JobEndpointResult
    {
        return $this->claimEndpointHandler->handle($jobId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function heartbeat(string $jobId, array $payload): JobEndpointResult
    {
        return $this->heartbeatEndpointHandler->handle($jobId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function submit(string $jobId, array $payload): JobEndpointResult
    {
        return $this->submitEndpointHandler->handle($jobId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function fail(string $jobId, array $payload): JobEndpointResult
    {
        return $this->failEndpointHandler->handle($jobId, $payload);
    }
}
