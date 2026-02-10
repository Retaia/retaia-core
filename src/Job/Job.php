<?php

namespace App\Job;

final class Job
{
    public function __construct(
        public readonly string $id,
        public readonly string $assetUuid,
        public readonly string $jobType,
        public readonly JobStatus $status,
        public readonly ?string $claimedBy,
        public readonly ?string $lockToken,
        public readonly ?\DateTimeImmutable $lockedUntil,
        /** @var array<string, mixed> */
        public readonly array $result = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'asset_uuid' => $this->assetUuid,
            'job_type' => $this->jobType,
            'status' => $this->status->value,
            'claimed_by' => $this->claimedBy,
            'lock_token' => $this->lockToken,
            'locked_until' => $this->lockedUntil?->format(DATE_ATOM),
            'result' => $this->result,
        ];
    }
}
