<?php

namespace App\Application\Job;

use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\ResolveAuthenticatedUserResult;
use App\Job\Job;

final class JobEndpointsHandler
{
    public function __construct(
        private ListClaimableJobsHandler $listClaimableJobsHandler,
        private ClaimJobHandler $claimJobHandler,
        private HeartbeatJobHandler $heartbeatJobHandler,
        private SubmitJobHandler $submitJobHandler,
        private FailJobHandler $failJobHandler,
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
    ) {
    }

    public function list(int $limit): JobEndpointResult
    {
        return new JobEndpointResult(
            JobEndpointResult::STATUS_SUCCESS,
            ['items' => $this->listClaimableJobsHandler->handle($limit)],
            null,
            $this->actorId()
        );
    }

    public function claim(string $jobId): JobEndpointResult
    {
        $actorId = $this->actorId();
        $result = $this->claimJobHandler->handle($jobId, $actorId);
        if ($result->status() === ClaimJobResult::STATUS_STATE_CONFLICT || !$result->job() instanceof Job) {
            return new JobEndpointResult(JobEndpointResult::STATUS_STATE_CONFLICT, null, null, $actorId);
        }

        return new JobEndpointResult(JobEndpointResult::STATUS_SUCCESS, null, $result->job(), $actorId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function heartbeat(string $jobId, array $payload): JobEndpointResult
    {
        $actorId = $this->actorId();
        $lockToken = trim((string) ($payload['lock_token'] ?? ''));
        if ($lockToken === '') {
            return new JobEndpointResult(JobEndpointResult::STATUS_LOCK_REQUIRED, null, null, $actorId);
        }

        $result = $this->heartbeatJobHandler->handle($jobId, $lockToken);
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
            ['locked_until' => $result->job()->lockedUntil?->format(DATE_ATOM)],
            $result->job(),
            $actorId
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function submit(string $jobId, array $payload): JobEndpointResult
    {
        $actorId = $this->actorId();
        $lockToken = trim((string) ($payload['lock_token'] ?? ''));
        $jobType = trim((string) ($payload['job_type'] ?? ''));
        if ($lockToken === '') {
            return new JobEndpointResult(JobEndpointResult::STATUS_LOCK_REQUIRED, null, null, $actorId);
        }
        if ($jobType === '') {
            return new JobEndpointResult(JobEndpointResult::STATUS_VALIDATION_FAILED, null, null, $actorId);
        }

        $result = $payload['result'] ?? [];
        if (!is_array($result)) {
            $result = [];
        }

        $submission = $this->submitJobHandler->handle($jobId, $lockToken, $jobType, $result, $this->actorRoles());
        if ($submission->status() === SubmitJobResult::STATUS_FORBIDDEN_SCOPE) {
            return new JobEndpointResult(JobEndpointResult::STATUS_FORBIDDEN_SCOPE, null, null, $actorId);
        }
        if ($submission->status() === SubmitJobResult::STATUS_VALIDATION_FAILED) {
            return new JobEndpointResult(JobEndpointResult::STATUS_VALIDATION_FAILED, null, null, $actorId);
        }
        if ($submission->status() !== SubmitJobResult::STATUS_SUBMITTED) {
            return new JobEndpointResult(
                JobEndpointResult::STATUS_LOCK_CONFLICT,
                null,
                null,
                $actorId,
                $submission->status() === SubmitJobResult::STATUS_STALE_LOCK_TOKEN ? 'STALE_LOCK_TOKEN' : 'LOCK_INVALID'
            );
        }
        if (!$submission->job() instanceof Job) {
            return new JobEndpointResult(JobEndpointResult::STATUS_LOCK_CONFLICT, null, null, $actorId, 'LOCK_INVALID');
        }

        return new JobEndpointResult(JobEndpointResult::STATUS_SUCCESS, null, $submission->job(), $actorId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function fail(string $jobId, array $payload): JobEndpointResult
    {
        $actorId = $this->actorId();
        $lockToken = trim((string) ($payload['lock_token'] ?? ''));
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
        if ($errorCode === '' || $message === '') {
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

        $failure = $this->failJobHandler->handle($jobId, $lockToken, $retryable, $errorCode, $message);
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

    public function actorId(): string
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return 'anonymous';
        }

        return (string) $authenticatedUser->id();
    }

    /**
     * @return array<int, string>
     */
    public function actorRoles(): array
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return [];
        }

        return $authenticatedUser->roles();
    }
}
