<?php

namespace App\Application\Auth\Port;

interface AdminActorGateway
{
    public function isAdmin(): bool;

    public function actorId(): ?string;
}
