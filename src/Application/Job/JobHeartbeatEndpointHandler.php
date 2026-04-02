<?php

namespace App\Application\Job;

use App\Job\Job;

final class JobHeartbeatEndpointHandler
{
    public function __construct(
        private HeartbeatJobHandler $heartbeatJobHandler,
        private JobEndpointActorContextResolver $actorContextResolver,
        private JobEndpointFencingTokenParser $fencingTokenParser,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(string $jobId, array $payload): JobEndpointResult
    {
        $actorId = $this->actorContextResolver->actorId();
        $lockToken = trim((string) ($payload['lock_token'] ?? ''));
        $fencingToken = $this->fencingTokenParser->parse($payload['fencing_token'] ?? null);
        if ($lockToken === '') {
            return new JobEndpointResult(JobEndpointResult::STATUS_LOCK_REQUIRED, null, null, $actorId);
        }
        if ($fencingToken === null) {
            return new JobEndpointResult(JobEndpointResult::STATUS_VALIDATION_FAILED, null, null, $actorId);
        }

        $result = $this->heartbeatJobHandler->handle($jobId, $actorId, $lockToken, $fencingToken);
        if ($result->status() !== HeartbeatJobResult::STATUS_HEARTBEATED) {
            return new JobEndpointResult(
                JobEndpointResult::STATUS_LOCK_CONFLICT,
                null,
                null,
                $actorId,
                $result->status() === HeartbeatJobResult::STATUS_STALE_LOCK_TOKEN ? 'STALE_LOCK_TOKEN' : 'LOCK_INVALID'
            );
        }
        if (!$result->job() instanceof Job) {
            return new JobEndpointResult(JobEndpointResult::STATUS_LOCK_CONFLICT, null, null, $actorId, 'LOCK_INVALID');
        }

        return new JobEndpointResult(
            JobEndpointResult::STATUS_SUCCESS,
            [
                'locked_until' => $result->job()->lockedUntil?->format(DATE_ATOM),
                'fencing_token' => $result->job()->fencingToken,
            ],
            $result->job(),
            $actorId
        );
    }
}
