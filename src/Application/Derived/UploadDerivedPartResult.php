<?php

namespace App\Application\Derived;

final class UploadDerivedPartResult
{
    public const STATUS_NOT_FOUND = 'NOT_FOUND';
    public const STATUS_STATE_CONFLICT = 'STATE_CONFLICT';
    public const STATUS_ACCEPTED = 'ACCEPTED';

    public function __construct(
        private string $status,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }
}
