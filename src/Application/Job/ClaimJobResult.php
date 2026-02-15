<?php

namespace App\Application\Job;

use App\Job\Job;

final class ClaimJobResult
{
    public const STATUS_CLAIMED = 'CLAIMED';
    public const STATUS_STATE_CONFLICT = 'STATE_CONFLICT';

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
