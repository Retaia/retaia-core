<?php

namespace App\Application\Auth;

final class ResolveAgentActorResult
{
    public const STATUS_AUTHORIZED = 'AUTHORIZED';
    public const STATUS_FORBIDDEN_ACTOR = 'FORBIDDEN_ACTOR';

    public function __construct(
        private string $status,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }
}
