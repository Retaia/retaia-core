<?php

namespace App\Application\Job;

use App\Job\Job;

final class SubmitJobResult
{
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_FORBIDDEN_SCOPE = 'FORBIDDEN_SCOPE';
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_STALE_LOCK_TOKEN = 'STALE_LOCK_TOKEN';
    public const STATUS_LOCK_INVALID = 'LOCK_INVALID';

    public function __construct(
        private string $status,
        private ?Job $job = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function job(): ?Job
    {
        return $this->job;
    }
}
