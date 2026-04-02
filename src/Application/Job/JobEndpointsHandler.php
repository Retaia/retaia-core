<?php

namespace App\Application\Job;

use App\Application\Auth\ResolveAuthenticatedUserHandler;

final class JobEndpointsHandler
{
    private JobEndpointActorContextResolver $actorContextResolver;
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
        $this->actorContextResolver = new JobEndpointActorContextResolver($this->resolveAuthenticatedUserHandler);
        $fencingTokenParser = new JobEndpointFencingTokenParser();

        $this->listEndpointHandler = new JobListEndpointHandler($this->listClaimableJobsHandler, $this->actorContextResolver);
        $this->claimEndpointHandler = new JobClaimEndpointHandler($this->claimJobHandler, $this->actorContextResolver);
        $this->heartbeatEndpointHandler = new JobHeartbeatEndpointHandler($this->heartbeatJobHandler, $this->actorContextResolver, $fencingTokenParser);
        $this->submitEndpointHandler = new JobSubmitEndpointHandler($this->submitJobHandler, $this->actorContextResolver, $fencingTokenParser);
        $this->failEndpointHandler = new JobFailEndpointHandler($this->failJobHandler, $this->actorContextResolver, $fencingTokenParser);
    }

    public function list(int $limit): JobEndpointResult
    {
        return $this->listEndpointHandler->handle($limit);
    }

    public function claim(string $jobId): JobEndpointResult
    {
        return $this->claimEndpointHandler->handle($jobId);
    }

    public function actorId(): string
    {
        return $this->actorContextResolver->actorId();
    }

    /**
     * @return list<string>
     */
    public function actorRoles(): array
    {
        return $this->actorContextResolver->actorRoles();
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
