<?php

namespace App\Application\Auth;

final class ResolveAdminActorResult
{
    public const STATUS_AUTHORIZED = 'AUTHORIZED';
    public const STATUS_FORBIDDEN_ACTOR = 'FORBIDDEN_ACTOR';

    public function __construct(
        private string $status,
        private ?string $actorId = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
