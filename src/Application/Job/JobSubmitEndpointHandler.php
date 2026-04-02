<?php

namespace App\Application\Job;

use App\Job\Job;

final class JobSubmitEndpointHandler
{
    public function __construct(
        private SubmitJobHandler $submitJobHandler,
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
        $jobType = trim((string) ($payload['job_type'] ?? ''));
        if ($lockToken === '' || $fencingToken === null || $jobType === '' || !$this->isAllowedJobType($jobType)) {
            return new JobEndpointResult(JobEndpointResult::STATUS_VALIDATION_FAILED, null, null, $actorId);
        }

        $result = $payload['result'] ?? [];
        if (!is_array($result)) {
            $result = [];
        }

        $submission = $this->submitJobHandler->handle($jobId, $actorId, $lockToken, $fencingToken, $jobType, $result, $this->actorContextResolver->actorRoles());
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

    private function isAllowedJobType(string $jobType): bool
    {
        return in_array($jobType, ['extract_facts', 'generate_preview', 'generate_thumbnails', 'generate_audio_waveform', 'transcribe_audio'], true);
    }
}
