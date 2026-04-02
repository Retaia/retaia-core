<?php

namespace App\Application\Job;

use App\Job\Job;

final class JobFailEndpointHandler
{
    public function __construct(
        private FailJobHandler $failJobHandler,
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
        $errorCode = trim((string) ($payload['error_code'] ?? ''));
        $message = trim((string) ($payload['message'] ?? ''));
        $retryable = (bool) ($payload['retryable'] ?? false);

        if ($lockToken === '') {
            return new JobEndpointResult(
                JobEndpointResult::STATUS_LOCK_REQUIRED,
                null,
                null,
                $actorId,
                null,
                $errorCode,
                $retryable
            );
        }
        if ($fencingToken === null || $errorCode === '' || $message === '') {
            return new JobEndpointResult(
                JobEndpointResult::STATUS_VALIDATION_FAILED,
                null,
                null,
                $actorId,
                null,
                $errorCode,
                $retryable
            );
        }

        $failure = $this->failJobHandler->handle($jobId, $actorId, $lockToken, $fencingToken, $retryable, $errorCode, $message);
        if ($failure->status() !== FailJobResult::STATUS_FAILED) {
            return new JobEndpointResult(
                JobEndpointResult::STATUS_LOCK_CONFLICT,
                null,
                null,
                $actorId,
                $failure->status() === FailJobResult::STATUS_STALE_LOCK_TOKEN ? 'STALE_LOCK_TOKEN' : 'LOCK_INVALID',
                $errorCode,
                $retryable
            );
        }
        if (!$failure->job() instanceof Job) {
            return new JobEndpointResult(
                JobEndpointResult::STATUS_LOCK_CONFLICT,
                null,
                null,
                $actorId,
                'LOCK_INVALID',
                $errorCode,
                $retryable
            );
        }

        return new JobEndpointResult(
            JobEndpointResult::STATUS_SUCCESS,
            null,
            $failure->job(),
            $actorId,
            null,
            $errorCode,
            $retryable
        );
    }
}
