<?php

namespace App\Application\Job;

use App\Job\Job;

final class JobEndpointResult
{
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_STATE_CONFLICT = 'STATE_CONFLICT';
    public const STATUS_FORBIDDEN_SCOPE = 'FORBIDDEN_SCOPE';
    public const STATUS_LOCK_REQUIRED = 'LOCK_REQUIRED';
    public const STATUS_LOCK_CONFLICT = 'LOCK_CONFLICT';
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';

    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        private string $status,
        private ?array $payload = null,
        private ?Job $job = null,
        private ?string $actorId = null,
        private ?string $conflictCode = null,
        private ?string $errorCode = null,
        private ?bool $retryable = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        return $this->payload;
    }

    public function job(): ?Job
    {
        return $this->job;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function conflictCode(): ?string
    {
        return $this->conflictCode;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function retryable(): ?bool
    {
        return $this->retryable;
    }
}
